$('.eqLogicAction[data-action=syncMerosSync]').on('click', function () {
    $('#div_alert').showAlert({message: '{{Synchronisation avec le cloud Meross en cours...}}', level: 'warning'});
    $.post({
        url: 'plugins/MerosSync/core/ajax/MerosSync.ajax.php',
        data: {
            action: 'syncMeross'
        },
        success: function (data, status) {
            // Test si l'appel a échoué
            if (data.state !== 'ok' || status !== 'success') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: '{{Synchronisation terminée}}', level: 'success'});
            window.location.reload();
        },
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        }
    });
});

$('.eqLogicAction[data-action=healthMerosSync]').on('click', function () {
    $('#md_modal').dialog({title: "{{Santé Meross}}"});
    $('#md_modal').load('index.php?v=d&plugin=MerosSync&modal=health').dialog('open');
});

$('.eqLogicAction[data-action=deleteAll]').on('click', function () {
    $('#div_alert').showAlert({message: '{{Suppression en cours...}}', level: 'warning'});
    $.post({
        url: 'plugins/MerosSync/core/ajax/MerosSync.ajax.php',
        data: {
            action: 'deleteAll'
        },
        success: function (data, status) {
            // Test si l'appel a échoué
            if (data.state !== 'ok' || status !== 'success') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            $('#div_alert').showAlert({message: '{{Suppression terminée}}', level: 'success'});
            window.location.reload();
        },
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
        }
    });
});

$("#table_cmd").sortable({
    axis: "y",
    cursor: "move",
    items: ".cmd",
    placeholder: "ui-state-highlight",
    tolerance: "intersect",
    forcePlaceholderSize: true
});

/*
 * Fonction pour l'ajout de commande
 */
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    // ID
    tr += '<td>';
    tr += '  <span class="cmdAttr" data-l1key="id"></span>';
    tr += '</td>';
    // Icon + Name
    tr += '<td>';
    tr += '  <div class="row">';
    tr += '    <div class="col-sm-3">';
    tr += '      <a class="cmdAction btn btn-default btn-sm" data-l1key="chooseIcon"><i class="fa fa-flag"></i> {{Icône}}</a>';
    tr += '      <span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left : 10px;"></span>';
    tr += '    </div>';
    tr += '    <div class="col-sm-9">';
    tr += '      <input class="cmdAttr form-control input-sm" data-l1key="name">';
    tr += '    </div>';
    tr += '  </div>';
    if (init(_cmd.type) == 'action'){
        tr += '  <select class="cmdAttr form-control input-sm" data-l1key="value" style="display : none;margin-top : 5px;" title="{{La valeur de la commande vaut par défaut la commande}}">';
        tr += '     <option value="">{{Aucune}}</option>';
        tr += '  </select>';
    }
    tr += '</td>';
    // Type
    tr += '<td>';
    tr += '  <span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '  <span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    // Etat
    tr += '<td>';
    tr += '  <span class="cmdAttr" data-l1key="htmlstate"></span>';
    tr += '</td>';
    // Visible + Historized + Inverted
    // Unit + Min + Max
    tr += '<td>';
    tr += '   <span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label></span> ';
    tr += '   <span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
    tr += '   <span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label></span> ';
    tr += '  <div class="row">';
    tr += '    <div class="col-xs-12 col-lg-4">';
    tr += '      <input class="cmdAttr form-control" data-l1key="unite" placeholder="Unité" title="{{Unité}}">';
    tr += '    </div>';
    tr += '    <div class="col-xs-12 col-lg-4">';
    tr += '      <input class="cmdAttr form-control" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}">';
    tr += '    </div>';
    tr += '    <div class="col-xs-12 col-lg-4">';
    tr += '      <input class="cmdAttr form-control" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}">';
    tr += '    </div>';
    tr += '  </div>';
    tr += '</td>';
    // Actions
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fa fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    var tr = $('#table_cmd tbody tr:last');
    jeedom.eqLogic.buildSelectCmd({
        id: $('.eqLogicAttr[data-l1key=id]').value(),
        filter: {type: 'info'},
        error: function (error) {
            $('#div_alert').showAlert({message: error.message, level: 'danger'});
        },
        success: function (result) {
            tr.find('.cmdAttr[data-l1key=value]').append(result);
            tr.setValues(_cmd, '.cmdAttr');
            jeedom.cmd.changeType(tr, init(_cmd.subType));
        }
    });
}
