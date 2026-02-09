<?php
  /* This file is part of Jeedom.
   *
   * Jeedom is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   *
   * Jeedom is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
   */
  require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

  if (!jeedom::apiAccess(init('apikey'), 'MerosSync'))
  {
    echo __('Vous n\'êtes pas autorisé à effectuer cette action', __FILE__);
    die();
  }

  $result = json_decode(file_get_contents("php://input"), true);
  $response = array('success' => true);
  log::add('MerosSync', 'debug', print_r($result, true));
  $action = $result['action'];

  if( $action == 'test' )
  {
    log::add('MerosSync', 'debug', 'Rien à faire pour '.$action);
  }
  else if ( $action == 'updateKeyValue' )
  {
    $eqLogic = eqLogic::byLogicalId($result['internal_id'], 'MerosSync');
    if (isset($result['channel']))
    {
      log::add('MerosSync', 'debug', 'Traitement de '.$action.' : '.$result['key']."_".$result['channel'].'='.$result['value']);
      $eqLogic->checkAndUpdateCmd($result['key']."_".$result['channel'], $result['value']);
    }
    else
    {
      log::add('MerosSync', 'debug', 'Traitement de '.$action.' : '.$result['key'].'='.$result['value']);
      $eqLogic->checkAndUpdateCmd($result['key'], $result['value']);
    }
  }
  else
  {
    $internal_id = $result['internal_id'];
    message::add('MerosSync', __('Nouvel équipement Meross disponible: Merci de lancer une synchronisation.', __FILE__));
  }
  echo json_encode($response);
?>
