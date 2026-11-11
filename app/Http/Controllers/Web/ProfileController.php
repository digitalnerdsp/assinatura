<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Perfil do usuário logado: nome e foto. A foto fica no disco privado (volume persistente). */
final class ProfileController extends Controller
{
    private const SIZE = 256;

    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.image' => 'Envie uma imagem JPG, PNG ou WebP.',
            'photo.mimes' => 'Envie uma imagem JPG, PNG ou WebP.',
            'photo.max' => 'A foto pode ter no máximo 5 MB.',
        ]);

        $user = $request->user();
        $user->name = $data['name'];

        if ($request->hasFile('photo')) {
            $path = $this->store($request->file('photo'));
            if ($path === null) {
                return back()->withErrors(['photo' => 'Não foi possível processar essa imagem. Tente outra.'])->withInput();
            }

            $this->forget($user);
            $user->avatar_path = $path;
        }

        $user->save();

        return redirect()->route('profile.edit')->with('status', 'Perfil atualizado.');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->forget($user);
        $user->forceFill(['avatar_path' => null])->save();

        return back()->with('status', 'Foto removida.');
    }

    /** Entrega a foto só a quem está logado e é do mesmo cliente (ou super admin). */
    public function photo(Request $request, User $user): StreamedResponse
    {
        $viewer = $request->user();
        abort_unless(
            $user->avatar_path
            && ($viewer->is($user) || $viewer->isSuperAdmin() || ($viewer->tenant_id !== null && $viewer->tenant_id === $user->tenant_id)),
            404,
        );
        abort_unless(Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Recorta para um quadrado e regrava como JPEG 256x256 (descarta metadados e qualquer conteúdo
     * embutido no arquivo). Sem a extensão GD, guarda o arquivo original já validado como imagem.
     */
    private function store(UploadedFile $file): ?string
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            $ext = match ($file->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $path = 'avatars/'.Str::uuid().'.'.$ext;
            Storage::disk('local')->put($path, $bytes);

            return $path;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG/WebP transparente vira fundo branco
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($dst, null, 88);
        $jpeg = (string) ob_get_clean();

        $path = 'avatars/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        return $path;
    }

    private function forget(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }
    }
}
