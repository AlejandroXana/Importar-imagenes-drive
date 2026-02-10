(function ($) {
    'use strict';

    /* ─── Helpers ─── */

    function extractFileId(url) {
        url = url.trim();
        var m;
        if ((m = url.match(/\/d\/([a-zA-Z0-9_-]+)/))) return { type: 'file', id: m[1] };
        if ((m = url.match(/\/folders\/([a-zA-Z0-9_-]+)/))) return { type: 'folder', id: m[1] };
        if ((m = url.match(/[?&]id=([a-zA-Z0-9_-]+)/))) return { type: 'file', id: m[1] };
        if (/^[a-zA-Z0-9_-]{20,}$/.test(url)) return { type: 'file', id: url };
        return null;
    }

    function formatSize(bytes) {
        if (!bytes) return '';
        var k = 1024, sizes = ['B', 'KB', 'MB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function log(msg, status) {
        var $log = $('#dmi-log');
        var cls = status === 'ok' ? 'ok' : (status === 'fail' ? 'fail' : '');
        $log.append('<div class="' + cls + '">' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function showLog() {
        $('#dmi-log-card').show();
        $('#dmi-log').empty();
        setProgress(0);
    }

    function setProgress(pct) {
        $('#dmi-progress-bar').css('width', pct + '%');
    }

    /* ─── Importar archivo por archivo (secuencial para no sobrecargar) ─── */

    function importFilesSequential(fileIds, callback) {
        var total = fileIds.length;
        var done = 0;
        var results = [];

        function next() {
            if (done >= total) {
                callback(results);
                return;
            }

            var id = fileIds[done];
            log('Importando ' + (done + 1) + '/' + total + ': ' + id + '...');

            $.post(dmi.ajax_url, {
                action: 'dmi_import_files',
                nonce: dmi.nonce,
                file_ids: [id]
            }, function (res) {
                done++;
                setProgress(Math.round((done / total) * 100));

                if (res.success && res.data && res.data[0]) {
                    var r = res.data[0];
                    if (r.success) {
                        log(r.filename + ' - Importada correctamente', 'ok');
                        results.push(r);
                    } else {
                        log(id + ' - Error: ' + r.error, 'fail');
                    }
                } else {
                    log(id + ' - Error en la respuesta del servidor', 'fail');
                }

                next();
            }).fail(function () {
                done++;
                setProgress(Math.round((done / total) * 100));
                log(id + ' - Error de conexion', 'fail');
                next();
            });
        }

        next();
    }

    /* ─── Boton: Importar por URLs ─── */

    $('#dmi-btn-import').on('click', function () {
        var $btn = $(this);
        var lines = $('#dmi-urls').val().split('\n').filter(function (l) { return l.trim(); });

        if (!lines.length) {
            alert('Pega al menos una URL de Google Drive.');
            return;
        }

        var fileIds = [];
        var folderIds = [];

        lines.forEach(function (line) {
            var parsed = extractFileId(line);
            if (!parsed) {
                log('URL no reconocida: ' + line, 'fail');
                return;
            }
            if (parsed.type === 'folder') {
                folderIds.push(parsed.id);
            } else {
                fileIds.push(parsed.id);
            }
        });

        $btn.prop('disabled', true).text('Importando...');
        showLog();

        // Primero resolver carpetas, luego importar todo
        var folderPromises = folderIds.map(function (fid) {
            return new Promise(function (resolve) {
                log('Explorando carpeta ' + fid + '...');
                $.post(dmi.ajax_url, {
                    action: 'dmi_list_folder',
                    nonce: dmi.nonce,
                    folder_id: fid
                }, function (res) {
                    if (res.success && res.data) {
                        log('Encontradas ' + res.data.length + ' imagenes en la carpeta.', 'ok');
                        res.data.forEach(function (f) { fileIds.push(f.id); });
                    } else {
                        log('No se pudieron listar imagenes de la carpeta ' + fid, 'fail');
                    }
                    resolve();
                }).fail(function () {
                    log('Error al explorar carpeta ' + fid, 'fail');
                    resolve();
                });
            });
        });

        Promise.all(folderPromises).then(function () {
            if (!fileIds.length) {
                log('No hay archivos para importar.', 'fail');
                $btn.prop('disabled', false).text('Importar imagenes');
                return;
            }

            log('Iniciando importacion de ' + fileIds.length + ' archivo(s)...');

            importFilesSequential(fileIds, function (results) {
                var ok = results.filter(function (r) { return r.success; }).length;
                log('--- Completado: ' + ok + '/' + fileIds.length + ' importadas correctamente ---',
                    ok === fileIds.length ? 'ok' : 'fail');
                $btn.prop('disabled', false).text('Importar imagenes');
            });
        });
    });

    /* ─── Boton: Explorar carpeta ─── */

    $('#dmi-btn-explore').on('click', function () {
        var $btn = $(this);
        var url = $('#dmi-folder-url').val().trim();

        if (!url) {
            alert('Introduce la URL o ID de una carpeta.');
            return;
        }

        var parsed = extractFileId(url);
        var folderId = parsed ? parsed.id : url;

        $btn.prop('disabled', true).text('Explorando...');
        $('#dmi-folder-results').hide();
        $('#dmi-folder-grid').empty();

        $.post(dmi.ajax_url, {
            action: 'dmi_list_folder',
            nonce: dmi.nonce,
            folder_id: folderId
        }, function (res) {
            $btn.prop('disabled', false).text('Explorar carpeta');

            if (!res.success || !res.data || !res.data.length) {
                alert(res.data || 'No se encontraron imagenes.');
                return;
            }

            var $grid = $('#dmi-folder-grid');

            res.data.forEach(function (file) {
                var thumbSrc = dmi.thumb_url + encodeURIComponent(file.id);
                var $item = $(
                    '<div class="dmi-grid-item" data-id="' + file.id + '">' +
                    '  <input type="checkbox" class="dmi-check" />' +
                    '  <div class="dmi-thumb">' +
                    '    <img src="' + thumbSrc + '" alt="' + file.name + '" loading="lazy" />' +
                    '  </div>' +
                    '  <div class="dmi-name" title="' + file.name + '">' + file.name + '</div>' +
                    '  <div class="dmi-size">' + formatSize(file.size) + '</div>' +
                    '</div>'
                );
                $grid.append($item);
            });

            $('#dmi-folder-results').show();
        }).fail(function () {
            $btn.prop('disabled', false).text('Explorar carpeta');
            alert('Error al conectar con el servidor.');
        });
    });

    /* ─── Seleccionar items del grid ─── */

    $(document).on('click', '.dmi-grid-item', function (e) {
        if ($(e.target).is('input')) return;
        var $cb = $(this).find('.dmi-check');
        $cb.prop('checked', !$cb.prop('checked'));
        $(this).toggleClass('selected', $cb.prop('checked'));
    });

    $(document).on('change', '.dmi-check', function () {
        $(this).closest('.dmi-grid-item').toggleClass('selected', $(this).prop('checked'));
    });

    $('#dmi-select-all').on('change', function () {
        var checked = $(this).prop('checked');
        $('.dmi-grid-item .dmi-check').prop('checked', checked);
        $('.dmi-grid-item').toggleClass('selected', checked);
    });

    /* ─── Boton: Importar seleccionadas ─── */

    $('#dmi-btn-import-selected').on('click', function () {
        var $btn = $(this);
        var ids = [];

        $('.dmi-grid-item.selected').each(function () {
            ids.push($(this).data('id'));
        });

        if (!ids.length) {
            alert('Selecciona al menos una imagen.');
            return;
        }

        $btn.prop('disabled', true).text('Importando...');
        showLog();
        log('Iniciando importacion de ' + ids.length + ' imagen(es)...');

        importFilesSequential(ids, function (results) {
            var ok = results.filter(function (r) { return r.success; }).length;
            log('--- Completado: ' + ok + '/' + ids.length + ' importadas correctamente ---',
                ok === ids.length ? 'ok' : 'fail');
            $btn.prop('disabled', false).text('Importar seleccionadas');
        });
    });

})(jQuery);
