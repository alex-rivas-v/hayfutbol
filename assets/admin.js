/* global hayfutbol, jQuery */
(function ($) {
    'use strict';

    var nonce   = hayfutbol.nonce;
    var ajaxUrl = hayfutbol.ajaxUrl;
    var imgUrl  = hayfutbol.imgUrl;

    var helpContent = {
        'api-token': {
            title : 'Dónde crear el API Token',
            img   : 'cf-api-token.webp',
            text  : 'Ve a <strong>dash.cloudflare.com</strong> &rarr; icono de tu cuenta (arriba a la derecha) &rarr; <em>My Profile</em> &rarr; pestaña <em>API Tokens</em> &rarr; <em>Create Token</em>. Usa la plantilla <em>Edit zone DNS</em> y selecciona la zona de tu dominio.',
        },
        'zone-id': {
            title : 'Dónde encontrar el Zone ID',
            img   : 'cf-zone-id.webp',
            text  : 'Ve a <strong>dash.cloudflare.com</strong> &rarr; selecciona tu dominio &rarr; pestaña <em>Overview</em>. En la columna derecha, dentro de la sección <em>API</em>, encontrarás el <em>Zone ID</em> (cadena de 32 caracteres hexadecimales).',
        },
    };

    $('.hf-help-btn').on('click', function () {
        var key  = $(this).data('help');
        var data = helpContent[ key ];

        if ( ! data ) { return; }

        // Mark active button.
        $('.hf-help-btn').removeClass('is-active');
        $(this).addClass('is-active');

        $('#hayfutbol-help-title').text( data.title );
        $('#hayfutbol-help-img').attr( 'src', imgUrl + data.img ).attr( 'alt', data.title );
        $('#hayfutbol-help-text').html( data.text );
        $('#hayfutbol-help-empty').hide();
        $('#hayfutbol-help-content').show();
    });

    $('#hayfutbol-save-token').on('click', function () {
        var token    = $('#hayfutbol_cf_api_token').val().trim();
        var $spinner = $('#hayfutbol-token-spinner');
        var $result  = $('#hayfutbol-token-result');

        if ( ! token ) {
            $result.removeClass('is-ok').addClass('is-error').text('Introduce el token antes de guardar.');
            return;
        }

        $(this).prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('').removeClass('is-ok is-error');

        $.post(ajaxUrl, { action: 'hayfutbol_save_token', nonce: nonce, token: token }, function (response) {
            $spinner.removeClass('is-active');
            $('#hayfutbol-save-token').prop('disabled', false);

            if (response.success) {
                $result.addClass('is-ok').text(response.data);
                $('#hayfutbol_cf_api_token').val('').attr('placeholder', 'Token guardado — introduce uno nuevo para cambiarlo');
                $('#hayfutbol-verify-token').prop('disabled', false);
            } else {
                $result.addClass('is-error').text(response.data);
            }
        });
    });

    $('#hayfutbol-verify-token').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#hayfutbol-token-spinner');
        var $result  = $('#hayfutbol-token-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('').removeClass('is-ok is-error');

        $.post(ajaxUrl, { action: 'hayfutbol_verify_token', nonce: nonce }, function (response) {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);

            if (response.success) {
                $result.addClass('is-ok').text(response.data);
            } else {
                $result.addClass('is-error').text(response.data);
            }
        });
    });

    $('#hayfutbol-detect-record').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#hayfutbol-detect-spinner');
        var $list    = $('#hayfutbol-records-list');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $list.empty();

        $.post(ajaxUrl, { action: 'hayfutbol_detect_record', nonce: nonce }, function (response) {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);

            if ( ! response.success ) {
                $list.html('<p style="color:#b32d2e">' + $('<span>').text(response.data).html() + '</p>');
                return;
            }

            var data = response.data;

            if ( data.auto_match ) {
                renderAutoSelected(data.auto_match, data.records);
            } else {
                $list.html( buildTable(data.records) );
            }
        });
    });

    function renderAutoSelected(record, allRecords) {
        var $list = $('#hayfutbol-records-list');

        var html = '<div class="hf-auto-record">'
            + '<div class="hf-auto-record__info">'
            + '<div class="hf-auto-record__name">' + $('<span>').text(record.name).html() + '</div>'
            + '<div class="hf-auto-record__ip">' + $('<span>').text(record.content).html()
            + ' &mdash; Proxy: ' + (record.proxied ? 'activo' : 'inactivo') + '</div>'
            + '</div>'
            + '<button type="button" class="button button-small" id="hayfutbol-change-record">Cambiar</button>'
            + '</div>'
            + '<div id="hayfutbol-all-records" style="display:none">' + buildTable(allRecords) + '</div>';

        $list.html(html);

        // Auto-fill the record ID field.
        $('#hayfutbol_cf_record_id').val(record.id);

        $('#hayfutbol-change-record').on('click', function () {
            $('#hayfutbol-all-records').slideDown(150);
            $(this).hide();
        });
    }

    function buildTable(records) {
        if ( ! records || ! records.length ) {
            return '<p>No se encontraron registros A.</p>';
        }
        var rows = records.map(function (r) {
            return '<tr>'
                + '<td><strong>' + $('<span>').text(r.name).html() + '</strong></td>'
                + '<td>' + $('<span>').text(r.content).html() + '</td>'
                + '<td>' + (r.proxied ? 'Activo' : 'Inactivo') + '</td>'
                + '<td><button type="button" class="button button-small hayfutbol-select-record"'
                + ' data-id="' + $('<span>').text(r.id).html() + '">Seleccionar</button></td>'
                + '</tr>';
        });
        return '<table class="widefat fixed striped" style="margin-top:6px;font-size:13px">'
            + '<thead><tr><th>Dominio</th><th>IP</th><th>Proxy</th><th></th></tr></thead>'
            + '<tbody>' + rows.join('') + '</tbody>'
            + '</table>';
    }

    $(document).on('click', '.hayfutbol-select-record', function () {
        $('#hayfutbol_cf_record_id').val( $(this).data('id') );
        $('#hayfutbol-records-list').empty();
    });

    function updateIntervalWarning() {
        if ( $('#hayfutbol_check_interval').val() === '60' ) {
            $('#hayfutbol-interval-warning').show();
        } else {
            $('#hayfutbol-interval-warning').hide();
        }
    }

    $('#hayfutbol_check_interval').on('change', updateIntervalWarning);
    updateIntervalWarning();

    $('#hayfutbol-retry-pinger').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#hayfutbol-pinger-spinner');
        var $result  = $('#hayfutbol-pinger-result');

        if ( $btn.prop('disabled') ) { return; }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('').removeClass('is-ok is-error');

        $.ajax({
            url      : ajaxUrl,
            method   : 'POST',
            dataType : 'json',
            timeout  : 25000,
            data     : { action: 'hayfutbol_retry_pinger', nonce: nonce },
        }).done(function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                $result.addClass('is-error').text(response.data || 'Error desconocido.');
            }
        }).fail(function (_jqXHR, status) {
            $btn.prop('disabled', false);
            var msg = status === 'timeout'
                ? 'Tiempo de espera agotado.'
                : 'Error de conexión. Inténtalo de nuevo.';
            $result.addClass('is-error').text(msg);
        }).always(function () {
            $spinner.removeClass('is-active');
        });
    });

    $('#hayfutbol-run-check').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#hayfutbol-check-spinner');
        var $result  = $('#hayfutbol-check-result');

        if ( $btn.prop('disabled') ) { return; }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('').removeClass('is-ok is-error');

        $.ajax({
            url      : ajaxUrl,
            method   : 'POST',
            dataType : 'json',
            timeout  : 30000,
            data     : { action: 'hayfutbol_run_check', nonce: nonce },
        }).done(function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                $result.addClass('is-error').text('Error: ' + (response.data || 'desconocido'));
            }
        }).fail(function (_jqXHR, status) {
            $btn.prop('disabled', false);
            var msg = status === 'timeout'
                ? 'Tiempo de espera agotado.'
                : 'Error de conexión. Inténtalo de nuevo.';
            $result.addClass('is-error').text(msg);
        }).always(function () {
            $spinner.removeClass('is-active');
        });
    });

})(jQuery);
