<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

/**
 * Valida os argumentos que a IA mandou contra o JSON Schema da ferramenta (subconjunto: object, string, integer, number,
 * boolean, enum, minimum, maximum, maxLength, required). Propriedades fora do schema (como tenant_id ou user_id) são descartadas.
 */
final class ArgumentValidator
{
    /**
     * @param  array<string,mixed>  $schema
     * @return array{ok:bool,args:array<string,mixed>,error:?string}
     */
    public function validate(array $schema, mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return ['ok' => false, 'args' => [], 'error' => 'Os argumentos precisam ser um objeto.'];
        }

        $props = (array) ($schema['properties'] ?? []);
        $args = [];
        foreach ($props as $name => $rule) {
            if (! array_key_exists($name, $raw) || $raw[$name] === null) {
                continue;
            }
            $error = $this->check($name, (array) $rule, $raw[$name]);
            if ($error !== null) {
                return ['ok' => false, 'args' => [], 'error' => $error];
            }
            $args[$name] = $raw[$name];
        }
        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (! array_key_exists($name, $args)) {
                return ['ok' => false, 'args' => [], 'error' => "Falta o argumento obrigatório \"{$name}\"."];
            }
        }

        return ['ok' => true, 'args' => $args, 'error' => null];
    }

    /** @param array<string,mixed> $rule */
    private function check(string $name, array $rule, mixed $value): ?string
    {
        $type = $rule['type'] ?? 'string';
        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (! $ok) {
            return "O argumento \"{$name}\" deveria ser do tipo {$type}.";
        }
        if (isset($rule['enum']) && ! in_array($value, (array) $rule['enum'], true)) {
            return "O argumento \"{$name}\" tem um valor não permitido.";
        }
        if (is_string($value) && isset($rule['maxLength']) && mb_strlen($value) > (int) $rule['maxLength']) {
            return "O argumento \"{$name}\" é longo demais.";
        }
        if (is_numeric($value) && ((isset($rule['minimum']) && $value < $rule['minimum']) || (isset($rule['maximum']) && $value > $rule['maximum']))) {
            return "O argumento \"{$name}\" está fora do intervalo permitido.";
        }

        return null;
    }
}
