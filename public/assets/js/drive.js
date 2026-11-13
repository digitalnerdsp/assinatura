/*
 * Pastas e etiquetas estilo Drive (Documentos criados): popups, árvore "Mover para…", apagar com números e
 * alternador grade/lista. Tudo por toque/clique (nada depende de passar o mouse ou de arrastar).
 */
(function () {
    'use strict';

    var MAX_LEVELS = 4;
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };
    var show = function (id) { bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show(); };
    var urlFor = function (tpl, id) { return tpl.replace('__ID__', encodeURIComponent(id)); };

    function setMethod(form, method) {
        var m = form.querySelector('input[name=_method]');
        if (!m) return;
        m.disabled = !method;
        if (method) m.value = method;
    }

    // ---- árvore de pastas (JSON do servidor)
    function findNode(nodes, id) {
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].id === id) return nodes[i];
            var inner = findNode(nodes[i].children, id);
            if (inner) return inner;
        }
        return null;
    }
    function height(node) {
        var h = 0;
        node.children.forEach(function (c) { h = Math.max(h, height(c)); });
        return 1 + h;
    }
    function subtreeIds(node, out) {
        out = out || [];
        out.push(node.id);
        node.children.forEach(function (c) { subtreeIds(c, out); });
        return out;
    }

    function radioRow(name, value, label, level, checked, disabled, reason) {
        var row = document.createElement('label');
        row.className = 'drive-tree-row drive-tap' + (disabled ? ' is-disabled' : '');
        row.style.paddingLeft = (0.5 + level * 1.25) + 'rem';
        var input = document.createElement('input');
        input.type = 'radio';
        input.name = name;
        input.value = value;
        input.className = 'form-check-input mt-0 me-2';
        input.checked = checked;
        input.disabled = disabled;
        var icon = document.createElement('i');
        icon.className = 'bx bx-folder me-1';
        var text = document.createElement('span');
        text.textContent = label;
        row.appendChild(input);
        row.appendChild(icon);
        row.appendChild(text);
        if (disabled && reason) {
            var why = document.createElement('small');
            why.className = 'ms-2 text-secondary';
            why.textContent = reason;
            row.appendChild(why);
        }
        return row;
    }

    function openMove(kind, el) {
        var modal = document.getElementById('modal-move');
        var form = modal.querySelector('form');
        var holder = $('#move-tree', modal);
        var tree = JSON.parse(modal.dataset.tree || '[]');
        var fieldName = kind === 'folder' ? 'parent_id' : 'folder_id';
        var current = kind === 'folder' ? (el.dataset.parent || '') : (el.dataset.folder || '');
        var node = kind === 'folder' ? findNode(tree, el.dataset.id) : null;
        var blocked = node ? subtreeIds(node) : [];
        var span = node ? height(node) : 0;

        form.action = urlFor(kind === 'folder' ? modal.dataset.urlFolder : modal.dataset.urlDoc, el.dataset.id);
        $('#move-subject', modal).textContent = (kind === 'folder' ? 'Pasta: ' : 'Documento: ') + (el.dataset.name || el.dataset.title || '');
        holder.textContent = '';
        holder.appendChild(radioRow(fieldName, '', 'Raiz (sem pasta)', 0, current === '', false));

        (function walk(nodes, level) {
            nodes.forEach(function (n) {
                var disabled = false, reason = '';
                if (kind === 'folder') {
                    if (blocked.indexOf(n.id) !== -1) { disabled = true; reason = 'a própria pasta e as subpastas dela'; }
                    else if (n.level + span > MAX_LEVELS) { disabled = true; reason = 'passaria de ' + MAX_LEVELS + ' níveis'; }
                } else if (n.level > MAX_LEVELS) {
                    disabled = true;
                }
                holder.appendChild(radioRow(fieldName, n.id, n.name, level, current === n.id, disabled, reason));
                walk(n.children, level + 1);
            });
        })(tree, 1);

        show('modal-move');
    }

    // ---- ações
    var handlers = {
        'new-folder': function (el) {
            var m = document.getElementById('modal-folder'), f = m.querySelector('form');
            f.action = m.dataset.urlStore;
            setMethod(f, null);
            f.querySelector('[name=parent_id]').disabled = false;
            f.querySelector('[name=parent_id]').value = el.dataset.parent || '';
            f.querySelector('[name=name]').value = '';
            $('.modal-title', m).textContent = el.dataset.parent ? 'Nova subpasta' : 'Nova pasta';
            show('modal-folder');
        },
        'rename-folder': function (el) {
            var m = document.getElementById('modal-folder'), f = m.querySelector('form');
            f.action = urlFor(m.dataset.urlUpdate, el.dataset.id);
            setMethod(f, 'PUT');
            f.querySelector('[name=parent_id]').disabled = true;
            f.querySelector('[name=name]').value = el.dataset.name || '';
            $('.modal-title', m).textContent = 'Renomear pasta';
            show('modal-folder');
        },
        'move-folder': function (el) { openMove('folder', el); },
        'move-doc': function (el) { openMove('doc', el); },
        'delete-folder': function (el) {
            var m = document.getElementById('modal-delete-folder'), f = m.querySelector('form');
            var impact = $('#delete-impact', m), confirm = $('#delete-confirm', m);
            f.action = urlFor(m.dataset.urlDestroy, el.dataset.id);
            $('#delete-subject', m).textContent = 'Apagar a pasta “' + (el.dataset.name || '') + '”?';
            impact.textContent = 'Calculando…';
            confirm.disabled = true;
            show('modal-delete-folder');

            fetch(urlFor(m.dataset.urlImpact, el.dataset.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(function (d) {
                    var where = d.destination ? '“' + d.destination + '”' : 'a lista de documentos, sem pasta';
                    var text = d.trash + (d.trash === 1 ? ' documento vai' : ' documentos vão') + ' para a lixeira (rascunhos, cancelados e expirados). ' +
                        d.keep + (d.keep === 1 ? ' continua' : ' continuam') + ' e passa' + (d.keep === 1 ? '' : 'm') + ' para ' + where + '.';
                    if (d.folders > 0) text += ' ' + d.folders + (d.folders === 1 ? ' subpasta também será apagada.' : ' subpastas também serão apagadas.');
                    impact.textContent = text;
                    confirm.disabled = false;
                })
                .catch(function () {
                    impact.textContent = 'Não foi possível calcular o que será afetado. Feche e tente de novo.';
                });
        },
        'new-tag': function () {
            var m = document.getElementById('modal-tag'), f = m.querySelector('form');
            f.action = m.dataset.urlStore;
            setMethod(f, null);
            f.querySelector('[name=name]').value = '';
            var first = f.querySelector('[name=color]');
            if (first) first.checked = true;
            syncColors(f);
            $('.modal-title', m).textContent = 'Nova etiqueta';
            show('modal-tag');
        },
        'edit-tag': function (el) {
            var m = document.getElementById('modal-tag'), f = m.querySelector('form');
            f.action = urlFor(m.dataset.urlUpdate, el.dataset.id);
            setMethod(f, 'PUT');
            f.querySelector('[name=name]').value = el.dataset.name || '';
            $$('[name=color]', f).forEach(function (r) { r.checked = r.value === el.dataset.color; });
            syncColors(f);
            $('.modal-title', m).textContent = 'Editar etiqueta';
            show('modal-tag');
        },
        'tags-doc': function (el) {
            var m = document.getElementById('modal-doc-tags'), f = m.querySelector('form');
            var picked = [];
            try { picked = JSON.parse(el.dataset.tags || '[]'); } catch (e) { picked = []; }
            f.action = urlFor(m.dataset.url, el.dataset.id);
            $('#doc-tags-subject', m).textContent = 'Documento: ' + (el.dataset.title || '');
            $$('input[name="tags[]"]', f).forEach(function (cb) { cb.checked = picked.indexOf(cb.value) !== -1; });
            show('modal-doc-tags');
        }
    };

    function syncColors(form) {
        $$('.drive-color', form).forEach(function (l) { l.classList.toggle('is-selected', l.querySelector('input').checked); });
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-drive-action]');
        if (!el || !handlers[el.dataset.driveAction]) return;
        e.preventDefault();
        handlers[el.dataset.driveAction](el);
    });
    document.addEventListener('change', function (e) {
        if (e.target.matches('.drive-color input')) syncColors(e.target.closest('form'));
    });
    ['modal-folder', 'modal-tag'].forEach(function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.addEventListener('shown.bs.modal', function () { var i = m.querySelector('[name=name]'); if (i) { i.focus(); i.select(); } });
    });

    // ---- grade / lista (preferência neste navegador)
    var box = document.getElementById('drive-folders');
    function applyView(v) {
        if (!box) return;
        box.classList.toggle('drive-grid', v !== 'list');
        box.classList.toggle('drive-list', v === 'list');
        $$('[data-drive-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.driveView === v ? 'true' : 'false'); });
    }
    var saved = 'grid';
    try { saved = localStorage.getItem('driveView') === 'list' ? 'list' : 'grid'; } catch (e) { /* navegação privada */ }
    applyView(saved);
    $$('[data-drive-view]').forEach(function (b) {
        b.addEventListener('click', function () {
            applyView(b.dataset.driveView);
            try { localStorage.setItem('driveView', b.dataset.driveView); } catch (e) { /* ignora */ }
        });
    });
})();
