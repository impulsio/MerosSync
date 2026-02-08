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

/* * ***************************Includes********************************* */
require_once dirname(__FILE__).'/../../../../core/php/core.inc.php';

class MerosSync extends eqLogic {
    /*
     * Fonction exécutée automatiquement par Jeedom
     */

    public static function cron10()
    {
      self::syncMeross();
    }
    /**
     * Call the meross Python daemon.
     * @param  string $action Action calling.
     * @param  string $args   Other arguments.
     * @return array  Result of the callMeross.
     */
    public static function callMeross($action, $args = '') {
        log::add('MerosSync', 'debug', 'callMeross ' . print_r($action, true) . ' ' .print_r($args, true));
        $apikey = jeedom::getApiKey('MerosSync');
        $sock = 'unix://' . jeedom::getTmpFolder('MerosSync') . '/daemon.sock';
        log::add('MerosSync', 'debug', 'Socket ' . $sock);
        $fp = stream_socket_client($sock, $errno, $errstr);
        $result = '';
        if ($fp) {
            $query = [ 'action' => $action, 'args' => $args, 'apikey' => $apikey ];
            fwrite($fp, json_encode($query));
            while (!feof($fp))
            {
                $result .= fgets($fp, 1024);
            }
            fclose($fp);
        }
        else {
          log::add('MerosSync', 'info', 'noStreamSocket ' . $errno . ' - ' . $errstr);
          log::add('MerosSync', 'error', 'Merci de démarrer le démon !');
          return array();
        }
        $result = (is_json($result)) ? json_decode($result, true) : $result;
        log::add('MerosSync', 'debug', 'result callMeross '.print_r($result, true));
        return $result;
    }
    /**
     * Sync all meross devices.
     * @return none
     */
    public static function syncMeross() {
        log::add('MerosSync', 'debug', __('Synchronisation des équipements depuis le Cloud Meross', __FILE__));
        $results = self::callMeross('syncMeross');
        if (count($results)==0 || count($results['result'])==0)
        {
          log::add('MerosSync', 'error', 'Aucun équipement connecté ou problème de connexion. Merci de consulter la log.');
        }
        else
        {
          foreach( $results['result'] as $key=>$device )
          {
              self::syncOneMeross($device);
          }
          log::add('MerosSync', 'debug', 'Check offline components');
          foreach (self::byType('MerosSync') as $eqLogic)
          {
            $inArray = false;
            foreach( $results['result'] as $key=>$device )
            {
                if ($device['internal_id'] == $eqLogic->getLogicalId())
                {
                  $inArray = true;
                }
            }
            if (!$inArray)
            {
              log::add('MerosSync', 'debug', 'OFFLINE ID '.$eqLogic->getLogicalId().' - '.$eqLogic->getEqType_name().' - '.$eqLogic->getName());
              $eqLogic->setConfiguration('online', '0');
              $eqLogic->save();
            }
          }
        }
        log::add('MerosSync', 'info', __('syncMeross: synchronisation terminée.', __FILE__));
    }
    /**
     * Sync one meross devices.
     * @return none
     */
    public static function syncOneMeross($device)
    {
        $key = $device['internal_id'];
        $eqLogic = self::byLogicalId($key, 'MerosSync');
        $update=false;
        # Création ou Update
        if (!is_object($eqLogic))
        {
          //Vérification avec l'ancien key (uuid)
          $eqLogic = self::byLogicalId($device['uuid'], 'MerosSync');
          if (is_object($eqLogic))
          {
            //Il existe avec l'ancienne clé => on met à jour vers internal_id
            log::add('MerosSync', 'debug', 'Mise à jour logicalID : ' . $device["name"] . ' - ' . $key);
            $eqLogic->setLogicalId($key);
            $eqLogic->save();
            $update=true;
          }
          else
          {
            //Il n'existe vraiment pas
            log::add('MerosSync', 'info', __('syncMeross: Ajout de ', __FILE__) . $device["name"] . ' - ' . $key);
            $eqLogic = new MerosSync();
            $eqLogic->setName($device['name']);
            $eqLogic->setEqType_name('MerosSync');
            $eqLogic->setLogicalId($key);
            $eqLogic->setConfiguration('uuid', $device['uuid']); // on sauvegarde l'uuid pour plus tard
            if ($device['type'] != '')
            {
                $eqLogic->setConfiguration('type', $device['type']);
            }
            if ($device['famille'] != '')
            {
                $eqLogic->setConfiguration('famille', $device['famille']);
            }
            if ($device['online'] != '')
            {
                $eqLogic->setConfiguration('online', $device['online']);
            } else
            {
                $eqLogic->setConfiguration('online', '0');
            }
            if( $device['online'] == '1' )
            {
                $eqLogic->setIsEnable(1);
                $eqLogic->save();
            } else
            {
                $eqLogic->setIsEnable(0);
                $eqLogic->save();
                $humanName = $eqLogic->getHumanName();
                message::add('MerosSync', $humanName.' '.__('semble manquant, il a été désactivé.', __FILE__));
            }
        }
      }
      else
      {
        $update=true;
      }
      if ($update)
      {
          log::add('MerosSync', 'debug', __('syncMeross: Mise à jour de ', __FILE__) . $device["name"] . ' - ' . $key);
          $eqLogic->setName($device['name']);
          if ($device['online'] != '')
          {
              $eqLogic->setConfiguration('online', $device['online']);
          } else
          {
              $eqLogic->setConfiguration('online', '0');
          }
      }
      if( $device['online'] == '1' )
      {
          if ($device['ip'] != '')
          {
              $eqLogic->setConfiguration('ip', $device['ip']);
              $eqLogic->save();
          }
          # Mise à jour des Commandes
          self::updateEqLogicCmds($eqLogic, $device);
          self::updateEqLogicVals($eqLogic, $device['values']);
      }
      # Si online, on continue
      log::add('MerosSync', 'debug',  __('syncMeross: En ligne : ', __FILE__) . $device["online"] . ' - ' . $key);
    }
    /**
     * Update Values.
     * @return none
     */
    public static function updateEqLogicVals($_eqLogic, $values)
    {
        # Valeurs
        log::add('MerosSync', 'debug', 'updateEqLogicVals: Update eqLogic values');
        foreach ($values as $key => $value)
        {
            if( $key == 'switch' )
            {
              log::add('MerosSync', 'debug', 'updateEqLogicVals:');
              foreach( $value as $id=>$state )
              {
                $_eqLogic->checkAndUpdateCmd('onoff_'.$id, intval($state));
                log::add('MerosSync', 'debug', 'syncMeross: - Mise à jour onoff_'.$id.' : '.$state);
              }
            }
            else
            {
                if( $key == "capacity" )
                {
                    if( $value == 1 || $value == 5 )
                    {
                        $value = __('Couleur', __FILE__);
                    } else
                    {
                        $value = __('Blanc', __FILE__);
                    }
                }
                else if( $key == "rgbval" )
                {
                    log::add('MerosSync', 'debug', 'syncMeross: - la couleur est '.$value);
                }
                $_eqLogic->checkAndUpdateCmd($key, $value);
            }
        }
    }
    /**
     * Sync one meross devices.
     * @return none
     */
    public static function updateEqLogicCmds($_eqLogic, $_device)
    {
        log::add('MerosSync', 'debug', 'updateEqLogicCmds: Update eqLogic commands');
        $i = 0;
        $order = 1;
        $family = $_device['famille'];
        log::add('MerosSync', 'debug', 'syncMeross: - Famille '.$family);
        # Switch
        $nb_switch = count($_device['onoff']);
        foreach ($_device['onoff'] as $key=>$value)
        {
          #l'interrupteur global ne fonctionne pas avec les portes de garage
            if(  $i==0 && $nb_switch>1 && $family != 'GenericGarageDoorOpener')
            {
                # All On
                $cmd = $_eqLogic->getCmd(null, 'on_'.$i);
                if (!is_object($cmd)) {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=on_'.$i);
                    $cmd = new MerosSyncCmd();
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    $cmd->setTemplate('dashboard', 'default');
                    $cmd->setTemplate('mobile', 'default');
                    $cmd->setIsVisible(1);
                    $cmd->setLogicalId('on_'.$i);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else {
                    log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=on_'.$i);
                }
                $cmd->setName('Marche '.$value);
                $cmd->setOrder($order);
                $cmd->save();
                $order++;
                # All off
                $cmd = $_eqLogic->getCmd(null, 'off_'.$i);
                if (!is_object($cmd)) {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=off_'.$i);
                    $cmd = new MerosSyncCmd();
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    $cmd->setTemplate('dashboard', 'default');
                    $cmd->setTemplate('mobile', 'default');
                    $cmd->setIsVisible(1);
                    $cmd->setLogicalId('off_'.$i);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else {
                    log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=off_'.$i);
                }
                $cmd->setName('Arrêt '.$value);
                $cmd->setOrder($order);
                $cmd->save();
                $order++;
                $i++;
            }
            else
            {
                # status
                $cmd = $_eqLogic->getCmd(null, 'onoff_'.$i);
                if (!is_object($cmd))
                {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=onoff_'.$i);
                    $cmd = new MerosSyncCmd();
                    $cmd->setType('info');
                    $cmd->setSubType('binary');
                    if( $family == 'GenericGarageDoorOpener' )
                    {
                        $cmd->setGeneric_type('GARAGE_STATE');
                    } elseif( $family == 'GenericBulb' )
                    {
                        $cmd->setGeneric_type('LIGHT_STATE');
                    } else
                    {
                        $cmd->setGeneric_type('ENERGY_STATE');
                    }
                    $cmd->setIsVisible(0);
                    $cmd->setIsHistorized(0);
                    $cmd->setLogicalId('onoff_'.$i);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else {
                    log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=onoff_'.$i);
                }
                $cmd->setConfiguration('repeatEventManagement','always');
                $cmd->setName($value);
                $cmd->setOrder($order);
                $cmd->save();
                $status_id = $cmd->getId();
                $order++;
                # off
                $cmd = $_eqLogic->getCmd(null, 'off_'.$i);
                if (!is_object($cmd))
                {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=off_'.$i);
                    $cmd = new MerosSyncCmd();
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    if( $family == 'GenericGarageDoorOpener' )
                    {
                        $cmd->setTemplate('dashboard', 'garage');
                        $cmd->setTemplate('mobile', 'garage');
                        $cmd->setGeneric_type('GB_CLOSE');
                    }
                    elseif( $family == 'GenericBulb' )
                    {
                        $cmd->setTemplate('dashboard', 'light');
                        $cmd->setTemplate('mobile', 'light');
                        $cmd->setGeneric_type('LIGHT_OFF');
                    }
                    else
                    {
                        $cmd->setTemplate('dashboard', 'prise');
                        $cmd->setTemplate('mobile', 'prise');
                        $cmd->setGeneric_type('ENERGY_OFF');
                    }
                    $cmd->setIsVisible(1);
                    $cmd->setLogicalId('off_'.$i);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else {
                    log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=off_'.$i);
                }
                if ($nb_switch==1)
                {
                  if( $family == 'GenericGarageDoorOpener' )
                  {
                    $cmd->setName('Fermer');
                  }
                  else {
                    $cmd->setName('Arrêt');
                  }
                }
                else
                {
                  $cmd->setName(__('Arrêt', __FILE__).' '.$value);
                }
                $cmd->setOrder($order);
                $cmd->save();
                $cmd->setValue($status_id);
                $cmd->save();
                $order++;
                # on
                $cmd = $_eqLogic->getCmd(null, 'on_'.$i);
                if (!is_object($cmd)) {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=on_'.$i);
                    $cmd = new MerosSyncCmd();
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    if( $family == 'GenericGarageDoorOpener' ) {
                        $cmd->setTemplate('dashboard', 'garage');
                        $cmd->setTemplate('mobile', 'garage');
                        $cmd->setGeneric_type('GB_OPEN');
                    } elseif( $family == 'GenericBulb' ) {
                        $cmd->setTemplate('dashboard', 'light');
                        $cmd->setTemplate('mobile', 'light');
                        $cmd->setGeneric_type('LIGHT_ON');
                    } else {
                        $cmd->setTemplate('dashboard', 'prise');
                        $cmd->setTemplate('mobile', 'prise');
                        $cmd->setGeneric_type('ENERGY_ON');
                    }
                    $cmd->setIsVisible(1);
                    $cmd->setLogicalId('on_'.$i);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else {
                    log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=on_'.$i);
                }
                if ($nb_switch==1)
                {
                  if( $family == 'GenericGarageDoorOpener' ) {
                    $cmd->setName('Ouvrir');
                  }
                  else {
                    $cmd->setName('Marche');
                  }
                }
                else
                {
                  $cmd->setName(__('Marche', __FILE__).' '.$value);
                }
                $cmd->setOrder($order);
                $cmd->save();
                $cmd->setValue($status_id);
                $cmd->save();
                $order++;
                $i++;
            }
        }
        # Refresh
        $cmd = $_eqLogic->getCmd(null, 'refresh');
        if (!is_object($cmd)) {
            log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=refresh');
            $cmd = new MerosSyncCmd();
            $cmd->setName('Refresh');
            $cmd->setType('action');
            $cmd->setSubType('other');
            $cmd->setGeneric_type('DONT');
            $cmd->setConfiguration('switch', 'read');
            $cmd->setIsVisible(1);
            $cmd->setLogicalId('refresh');
            $cmd->setEqLogic_id($_eqLogic->getId());
        } else {
            log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=refresh');
        }
        $cmd->setOrder($order);
        $cmd->save();
        $order++;

        # Electicité
        if( $_device['elec'] )
        {
            # Puissance
            $cmd = $_eqLogic->getCmd(null, 'power');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=power');
                $cmd = new MerosSyncCmd();
                $cmd->setName(__('Puissance', __FILE__));
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setGeneric_type('POWER');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setTemplate('dashboard', 'gauge');
                $cmd->setTemplate('mobile', 'gauge');
                $cmd->setLogicalId('power');
                $cmd->setConfiguration('minValue', 0);
                $cmd->setConfiguration('maxValue', 4000);
                $cmd->setUnite('W');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=power');
            }
            $cmd->setConfiguration('historyPurge','-2 years');
            $cmd->setConfiguration('repeatEventManagement','always');
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Courant
            $cmd = $_eqLogic->getCmd(null, 'current');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=current');
                $cmd = new MerosSyncCmd();
                $cmd->setName(__('Courant', __FILE__));
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setTemplate('dashboard', 'gauge');
                $cmd->setTemplate('mobile', 'gauge');
                $cmd->setLogicalId('current');
                $cmd->setGeneric_type('GENERIC_INFO');
                $cmd->setConfiguration('minValue', 0);
                $cmd->setConfiguration('maxValue', 17);
                $cmd->setUnite('A');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=current');
            }
            $cmd->setConfiguration('historyPurge','-2 years');
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Tension
            $cmd = $_eqLogic->getCmd(null, 'tension');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=tension');
                $cmd = new MerosSyncCmd();
                $cmd->setName(__('Tension', __FILE__));
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setGeneric_type('VOLTAGE');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setTemplate('dashboard', 'gauge');
                $cmd->setTemplate('mobile', 'gauge');
                $cmd->setLogicalId('tension');
                $cmd->setConfiguration('minValue', 0);
                $cmd->setConfiguration('maxValue', 250);
                $cmd->setUnite('V');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=tension');
            }
            $cmd->setConfiguration('historyPurge','-2 years');
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
        }

        # Consommation
        if( $_device['conso'] )
        {
            # Ce Jour
            $cmd = $_eqLogic->getCmd(null, 'conso_totale');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=conso_totale');
                $cmd = new MerosSyncCmd();
                $cmd->setName(__('Consommation', __FILE__));
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setGeneric_type('CONSUMPTION');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(1);
                $cmd->setTemplate('dashboard', 'tile');
                $cmd->setTemplate('mobile', 'tile');
                $cmd->setLogicalId('conso_totale');
                $cmd->setUnite('kWh');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {

                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=conso_totale');
            }
            $cmd->setConfiguration('historyPurge','-2 years');
            $cmd->setConfiguration('repeatEventManagement','always');
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
        }

        # Lampe - Luminosité
        if( $_device['lumin'] )
        {
            # Luminance information
            $cmd = $_eqLogic->getCmd(null, 'lumival');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=lumival');
                $cmd = new MerosSyncCmd();
                $cmd->setName('lumi');
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setGeneric_type('LIGHT_STATE');
                $cmd->setIsVisible(0);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('lumival');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=lumival');
            }
            $cmd->setConfiguration('minValue', 0);
            $cmd->setConfiguration('maxValue', 100);
            $cmd->setUnite('%');
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            $status_id =  $cmd->getId();
            # Luminance setter
            $cmd = $_eqLogic->getCmd(null, 'lumiset');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=lumiset');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Luminosité');
                $cmd->setType('action');
                $cmd->setSubType('slider');
                $cmd->setGeneric_type('LIGHT_SLIDER');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('lumiset');
                $cmd->setTemplate('dashboard', 'light');
                $cmd->setTemplate('mobile', 'light');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=lumiset');
            }
            $cmd->setConfiguration('minValue', 0);
            $cmd->setConfiguration('maxValue', 100);
            $cmd->setOrder($order);
            $cmd->save();
            $cmd->setValue($status_id);
            $cmd->save();
            $order++;
        }

        if( $_device['tempe'] || $_device['heat'] )
        {
            # Temperature actuelle
            $cmd = $_eqLogic->getCmd(null, 'tempcur');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=tempcur');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Température actuelle');
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                if ($_device['tempe'])
                {
                  $cmd->setGeneric_type('LIGHT_COLOR_TEMP');
                }
                else
                {
                  $cmd->setGeneric_type('THERMOSTAT_TEMPERATURE');
                  $cmd->setUnite('°C');
                }
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('tempcur');
                $cmd->setTemplate('dashboard', 'tile');
                $cmd->setTemplate('mobile', 'tile');
                $cmd->setEqLogic_id($_eqLogic->getId());
                $cmd->setConfiguration('minValue', -30);
                $cmd->setConfiguration('maxValue', 110);
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=tempcur');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Temperature information
            $cmd = $_eqLogic->getCmd(null, 'tempval');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=tempval');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Température cible');
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                if ($_device['tempe'])
                {
                  $cmd->setGeneric_type('LIGHT_COLOR_TEMP');
                }
                else
                {
                  $cmd->setGeneric_type('HEATING_STATE');
                  $cmd->setUnite('°C');
                }
                $cmd->setIsVisible(0);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('tempval');
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=tempval');
            }
            $cmd->setConfiguration('minValue', $_device['minval']);
            $cmd->setConfiguration('maxValue', $_device['maxval']);
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            $status_id =  $cmd->getId();
            # Temperature setter
            $cmd = $_eqLogic->getCmd(null, 'tempset');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=tempset');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Changer la température');
                $cmd->setType('action');
                $cmd->setSubType('slider');
                if ($_device['tempe'])
                {
                  $cmd->setGeneric_type('LIGHT_SET_COLOR_TEMP');
                }
                else
                {
                  $cmd->setGeneric_type('THERMOSTAT_SET_SETPOINT');
                }
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('tempset');
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=tempset');
            }
            $cmd->setConfiguration('minValue', $_device['minval']);
            $cmd->setConfiguration('maxValue', $_device['maxval']);
            $cmd->setOrder($order);
            $cmd->save();
            $cmd->setValue($status_id);
            $cmd->save();
            $order++;

            if ($_device['heat'])
            {
              # Heating mode
              $cmd = $_eqLogic->getCmd(null, 'mode');
              if (!is_object($cmd)) {
                  log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=mode');
                  $cmd = new MerosSyncCmd();
                  $cmd->setName('Mode');
                  $cmd->setType('info');
                  $cmd->setSubType('string');
                  $cmd->setGeneric_type('THERMOSTAT_MODE');
                  $cmd->setIsVisible(1);
                  $cmd->setIsHistorized(0);
                  $cmd->setLogicalId('mode');
                  $cmd->setTemplate('dashboard', 'default');
                  $cmd->setTemplate('mobile', 'default');
                  $cmd->setEqLogic_id($_eqLogic->getId());
              } else {
                  log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=mode');
              }
              $cmd->setOrder($order);
              $cmd->save();
              $order++;

              # Heating mode
              $cmd = $_eqLogic->getCmd(null, 'state');
              if (!is_object($cmd)) {
                  log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=state');
                  $cmd = new MerosSyncCmd();
                  $cmd->setName('Etat');
                  $cmd->setType('info');
                  $cmd->setSubType('string');
                  $cmd->setGeneric_type('THERMOSTAT_MODE');
                  $cmd->setIsVisible(1);
                  $cmd->setIsHistorized(0);
                  $cmd->setLogicalId('state');
                  $cmd->setTemplate('dashboard', 'default');
                  $cmd->setTemplate('mobile', 'default');
                  $cmd->setEqLogic_id($_eqLogic->getId());
              } else {
                  log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=state');
              }
              $cmd->setOrder($order);
              $cmd->save();
              $order++;
            }

            if (is_array($_device['modes']))
            {
              foreach ($_device['modes'] as $key => $value)
              {
                $cmd = $_eqLogic->getCmd(null, 'tempmode_'.$key);
                if (!is_object($cmd))
                {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=tempmode_'.$key);
                    $cmd = new MerosSyncCmd();
                    $cmd->setName($value);
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    $cmd->setIsVisible(1);
                    $cmd->setIsHistorized(0);
                    $cmd->setTemplate('dashboard', 'default');
                    $cmd->setTemplate('mobile', 'default');
                    $cmd->setLogicalId('tempmode_'.$key);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else
                {
                  $cmd->setName($value);
                  log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=setTempMode_'.$key);
                }
                $cmd->setOrder($order);
                $cmd->save();
                $order++;
              }
            }
        }

        if( $_device['isrgb'] )
        {
            # Color information
            $cmd = $_eqLogic->getCmd(null, 'rgbval');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=rgbval');
                $cmd = new MerosSyncCmd();
                $cmd->setName('rgb');
                $cmd->setType('info');
                $cmd->setSubType('string');
                $cmd->setGeneric_type('LIGHT_COLOR');
                $cmd->setIsVisible(0);
                $cmd->setIsHistorized(0);
                $cmd->setLogicalId('rgbval');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=rgbval');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            $status_id = $cmd->getId();
            # Color setter
            $cmd = $_eqLogic->getCmd(null, 'rgbset');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=rgbset');
                $cmd = new MerosSyncCmd();
                $cmd->setName(__('Couleur', __FILE__));
                $cmd->setType('action');
                $cmd->setSubType('color');
                $cmd->setGeneric_type('LIGHT_SET_COLOR');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setLogicalId('rgbset');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=rgbset');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $cmd->setValue($status_id);
            $cmd->save();
            $order++;
        }
        # Light Mode
        if( $_device['lightmode'] )
        {
            # information
            $cmd = $_eqLogic->getCmd(null, 'lightmode');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=lightmode');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Mode lumière');
                $cmd->setType('info');
                $cmd->setSubType('string');
                $cmd->setGeneric_type('GENERIC_INFO');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setLogicalId('lightmode');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=lightmode');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;

            if (is_array($_device['modes']))
            {
              foreach ($_device['modes'] as $key => $value)
              {
                $cmd = $_eqLogic->getCmd(null, 'lightmode_'.$key);
                if (!is_object($cmd))
                {
                    log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=lightmode_'.$key);
                    $cmd = new MerosSyncCmd();
                    $cmd->setName($value);
                    $cmd->setType('action');
                    $cmd->setSubType('other');
                    $cmd->setIsVisible(1);
                    $cmd->setIsHistorized(0);
                    $cmd->setTemplate('dashboard', 'default');
                    $cmd->setTemplate('mobile', 'default');
                    $cmd->setLogicalId('lightmode_'.$key);
                    $cmd->setEqLogic_id($_eqLogic->getId());
                } else
                {
                  $cmd->setName($value);
                  log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=setLightmode_'.$key);
                }
                $cmd->setOrder($order);
                $cmd->save();
                $order++;
              }
            }
        }

        #Roller
        if( $_device['roller'] )
        {
            # Roller UP
            $cmd = $_eqLogic->getCmd(null, 'up_0');
            if (!is_object($cmd))
            {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=up_0');
                $cmd = new MerosSyncCmd();
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setGeneric_type('FLAP_UP');
                $cmd->setIsVisible(1);
                $cmd->setLogicalId('up_0');
                $cmd->setEqLogic_id($_eqLogic->getId());
                $cmd->setName('Monter');
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=up_0');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Roller down
            $cmd = $_eqLogic->getCmd(null, 'down_0');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=down_0');
                $cmd = new MerosSyncCmd();
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setGeneric_type('FLAP_DOWN');
                $cmd->setIsVisible(1);
                $cmd->setLogicalId('down_0');
                $cmd->setEqLogic_id($_eqLogic->getId());
                $cmd->setName('Descendre');
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=down_0');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Roller stop
            $cmd = $_eqLogic->getCmd(null, 'stop_0');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=stop_0');
                $cmd = new MerosSyncCmd();
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setGeneric_type('FLAP_STOP');
                $cmd->setIsVisible(1);
                $cmd->setLogicalId('stop_0');
                $cmd->setEqLogic_id($_eqLogic->getId());
                $cmd->setName('STOP');
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=stop_0');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
            # Roller icone position
            $cmd = $_eqLogic->getCmd(null, 'position');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=position');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Etat');
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setGeneric_type('FLAP_STATE');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setLogicalId('position');
                $cmd->setEqLogic_id($_eqLogic->getId());
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=position');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $status_id =  $cmd->getId();
            $order++;
            # Roller changement position
            $cmd = $_eqLogic->getCmd(null, 'changePosition');
            if (!is_object($cmd)) {
                log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=changePosition');
                $cmd = new MerosSyncCmd();
                $cmd->setName('Ouverture');
                $cmd->setType('action');
                $cmd->setSubType('slider');
                $cmd->setGeneric_type('FLAP_SLIDER');
                $cmd->setIsVisible(1);
                $cmd->setIsHistorized(0);
                $cmd->setTemplate('dashboard', 'default');
                $cmd->setTemplate('mobile', 'default');
                $cmd->setLogicalId('changePosition');
                $cmd->setEqLogic_id($_eqLogic->getId());
                $cmd->setValue($status_id);
                $cmd->setConfiguration('minValue', 0);
                $cmd->setConfiguration('maxValue', 100);
                $cmd->setUnite('%');
            } else {
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=changePosition');
            }
            $cmd->setOrder($order);
            $cmd->save();
            $order++;
        }

        # Spray Mode
        if( $_device['spray'] )
        {
          if (is_array($_device['spraymodes']))
          {
            foreach ($_device['spraymodes'] as $key => $value)
            {
              $cmd = $_eqLogic->getCmd(null, 'spray_'.$key);
              if (!is_object($cmd))
              {
                  log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=spray_'.$key);
                  $cmd = new MerosSyncCmd();
                  $cmd->setName($value);
                  $cmd->setType('action');
                  $cmd->setSubType('other');
                  $cmd->setIsVisible(1);
                  $cmd->setIsHistorized(0);
                  $cmd->setTemplate('dashboard', 'default');
                  $cmd->setTemplate('mobile', 'default');
                  $cmd->setLogicalId('spray_'.$key);
                  $cmd->setEqLogic_id($_eqLogic->getId());
              } else
              {
                $cmd->setName($value);
                log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=spray_'.$key);
              }
              $cmd->setOrder($order);
              $cmd->save();
              $order++;
            }
          }
          # Spray information
          $cmd = $_eqLogic->getCmd(null, 'spray');
          if (!is_object($cmd))
          {
              log::add('MerosSync', 'debug', 'syncMeross: - Add cmd=spray');
              $cmd = new MerosSyncCmd();
              $cmd->setName('Mode diffusion');
              $cmd->setType('info');
              $cmd->setSubType('string');
              $cmd->setGeneric_type('GENERIC_INFO');
              $cmd->setIsVisible(1);
              $cmd->setIsHistorized(0);
              $cmd->setTemplate('dashboard', 'default');
              $cmd->setTemplate('mobile', 'default');
              $cmd->setLogicalId('spray');
              $cmd->setEqLogic_id($_eqLogic->getId());
          } else
          {
              log::add('MerosSync', 'debug', 'syncMeross: - Update cmd=spray');
          }
          $cmd->setOrder($order);
          $cmd->save();
          $order++;
        }
        log::add('MerosSync', 'debug', 'updateEqLogicCmdVal: Update eqLogic informations Completed');
    }
    /**
     * Effacer tous les EqLogic
     * @return none
     */
    public function deleteAll()
    {
        log::add('MerosSync','debug','***** DELETE ALL *****');
        $eqLogics = eqLogic::byType('MerosSync');
        foreach ($eqLogics as $eqLogic) {
            $eqLogic->remove();
        }
        return array(true, 'OK');
    }
    /**
     * Get dependancy information
     * @return array Python3 command return.
     */
    public static function dependancy_info()
    {
        $return = [
            'state' => 'nok',
            'log' => 'MerosSync_update',
            'progress_file' => jeedom::getTmpFolder('MerosSync') . '/dependance'
        ];
        $meross_version = trim(file_get_contents(dirname(__FILE__) . '/../../resources/meross-iot_version.txt'));
        $cmd = dirname(__FILE__) . '/../../resources/.venvs/merosssync/bin/pip list | grep meross[-_]iot | grep '.$meross_version.' | wc -l';
        exec($cmd, $output, $return_var);
        if ($output[0] == "1")
        {
            $return['state'] = 'ok';
        }
        return $return;
    }
    /**
     * Install dependancies.
     * @return array Shell script command return.
     */
    public static function dependancy_install()
    {
        log::remove(__CLASS__ . '_update');
        return [
            'script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('MerosSync') . '/dependance',
            'log' => log::getPathToLog(__CLASS__ . '_update')
        ];
    }
    /**
     * Start python daemon.
     * @return array Shell command return.
     */
    public static function deamon_start()
    {
        //Arrêt du démon avant de le relancer
        self::deamon_stop();

        //Vérification que le démon est lançable
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $user = config::byKey('MerossUSR', 'MerosSync');
        $pswd = addslashes(config::byKey('MerossPWD', 'MerosSync'));

        $MerosSync_path = realpath(dirname(__FILE__) . '/../../resources');
        $callback = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/MerosSync/core/php/jeeMerosSync.php';

        $cmd = $MerosSync_path.'/.venvs/merosssync/bin/python3 ' . $MerosSync_path . '/MerossIOTd/MerossIOTd.py';
        $cmd.= ' --muser "'.$user.'"';
        $cmd.= ' --mpswd "'.$pswd.'"';
        $cmd.= ' --callback '.$callback;
        $cmd.= ' --apikey '.jeedom::getApiKey('MerosSync');
        $cmd.= ' --loglevel '.log::convertLogLevel(log::getLogLevel('MerosSync'));
        $cmd.= ' --pid '.jeedom::getTmpFolder('MerosSync') . '/daemon.pid';
        $cmd.= ' --errorfile '.jeedom::getTmpFolder('MerosSync') . '/errordaemon.pid';
        $cmd.= ' --socket '.jeedom::getTmpFolder('MerosSync') . '/daemon.sock';
        $cmd.= ' --logfile '.log::getPathToLog('MerosSync');
        $cmd.= ' --versionFile '.$MerosSync_path.'/meross-iot_version.txt';

        $log = str_replace($pswd, 'xxx', str_replace($user, 'xxx', $cmd));
        log::add('MerosSync','info',__('Lancement démon meross :', __FILE__).' '.$log);
        $result = exec($cmd . ' >> ' . log::getPathToLog('MerosSync') . ' 2>&1 &');
        $i = 0;
        while ($i < 60)
        {
            $deamon_info = self::deamon_info();
            if (($deamon_info['state'] == 'ok') || ($deamon_info['state'] == 'error'))
            {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($deamon_info['state'] == 'error')
        {
            log::add('MerosSync', 'error', 'Le démon meross est en erreur, vérifiez la log', 'unableStartDeamon');
            return false;
        }
        else if ($i >= 60)
        {
            log::add('MerosSync', 'error', 'Le démon meross a mis trop de temps à démarrer, vérifiez la log', 'unableStartDeamon');
            return false;
        }
        message::removeAll('MerosSync', 'unableStartDeamon');
        log::add('MerosSync','info',__('Démon meross lancé.', __FILE__));
        return true;
    }
    /**
     * Stop python daemon.
     * @return array Shell command return.
     */
    public static function deamon_stop()
    {
      log::add('MerosSync','info','Arrêt démon meross.');
      $pid_file = jeedom::getTmpFolder('MerosSync') . '/daemon.pid';
      if (file_exists($pid_file)) {
        $pid = intval(trim(file_get_contents($pid_file)));
        log::add('MerosSync','info','Arrêt job '.$pid);
        system::kill($pid);
      }
      system::kill('MerossIOTd.py');
      system::fuserk(config::byKey('socketport', 'MerosSync'));
    }
    /**
     * Return information (status) about daemon.
     * @return array Shell command return.
     */
    public static function deamon_info()
    {
      $pid_file = jeedom::getTmpFolder('MerosSync') . '/daemon.pid';
      $error_file = jeedom::getTmpFolder('MerosSync') . '/errordaemon.pid';
      $return = ['state' => 'nok'];

      if (file_exists($pid_file))
      {
        if (@posix_getsid(trim(file_get_contents($pid_file))))
        {
          $return['state'] = 'ok';
        }
        else
        {
          shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
        }
      }
      elseif (file_exists($error_file))
      {
        log::add('MerosSync', 'debug', 'File error contains '.file_get_contents($error_file));
        $return['state'] = 'error';
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $error_file . ' 2>&1 > /dev/null');
      }
      $return['launchable'] = 'ok';

      if (self::dependancy_info()['state'] == 'nok')
      {
        $cache = cache::byKey('dependancy' . 'MerosSync');
        $cache->remove();
        $return['launchable'] = 'nok';
        $return['launchable_message'] = __('Veuillez (ré-)installer les dépendances', __FILE__);
      }
      return $return;
    }
}

class MerosSyncCmd extends cmd {
    public function execute($_options = array())
    {
        $eqLogic = $this->getEqLogic();
        $action = $this->getLogicalId();
        log::add('MerosSync', 'debug', $eqLogic->getLogicalId().' = action: '. $action.' - params '.json_encode($_options) );
        $execute = false;
        // Handle actions like on_x off_x
        $splitAction = explode("_", $action);
        $action = $splitAction[0];
        $channel = $splitAction[1];
        switch ($action)
        {
            case "on":
                $res = MerosSync::callMeross('setOn', [$eqLogic->getLogicalId(), $channel]);
                log::add('MerosSync', 'debug', 'setOn: '.json_encode($res['result']));
                $cmd = $eqLogic->getCmd(null, 'onoff_'.$channel);
                if (!is_object($cmd))
                {
                  //Interrupteur global
                  $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                  log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                  MerosSync::syncOneMeross($res['result']);
                }
                else
                {
                  log::add('MerosSync', 'debug', 'mise à jour état '.$res['result']);
                  $eqLogic->checkAndUpdateCmd('onoff_'.$channel, $res['result']);
                }
                break;
            case "off":
                $res = MerosSync::callMeross('setOff', [$eqLogic->getLogicalId(), $channel]);
                log::add('MerosSync', 'debug', 'setOff: '.json_encode($res['result']));
                $cmd = $eqLogic->getCmd(null, 'onoff_'.$channel);
                if (!is_object($cmd))
                {
                  //Interrupteur global
                  $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                  log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                  MerosSync::syncOneMeross($res['result']);
                }
                else
                {
                  log::add('MerosSync', 'debug', 'mise à jour état'.$res['result']);
                  $eqLogic->checkAndUpdateCmd('onoff_'.$channel, $res['result']);
                }
                break;
            case "lumiset":
                $res = MerosSync::callMeross('setLumi', [$eqLogic->getLogicalId(), $_options['slider']]);
                log::add('MerosSync', 'debug', 'setLumi '.$_options['slider'].': '.$res['result']);
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "tempset":
                //$cmd = $eqLogic->getCmd(null, 'lumival');
                //$lumi = $cmd->execCmd();
                $res = MerosSync::callMeross('setTemp', [$eqLogic->getLogicalId(), $_options['slider']]);
                log::add('MerosSync', 'debug', 'setTemp '.$_options['slider'].': '.$res['result']);
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "tempmode":
                log::add('MerosSync', 'debug', 'call setTempMode with mode '.$channel);
                $res = MerosSync::callMeross('setTempMode', [$eqLogic->getLogicalId(), $channel]);
                log::add('MerosSync', 'debug', 'setTempMode: '.json_encode($res['result']));
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "rgbset":
                log::add('MerosSync', 'debug', 'callSetRGB '.$_options['color'].' => '.substr($_options['color'],-6));
                $res = MerosSync::callMeross('setRGB', [$eqLogic->getLogicalId(), substr($_options['color'],-6)]);
                log::add('MerosSync', 'debug', 'setRGB '.$_options['color'].' : '.$res['result']);
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "spray":
                log::add('MerosSync', 'debug', 'call setSpray with mode '.$channel);
                $res = MerosSync::callMeross('setSpray', [$eqLogic->getLogicalId(), $channel]);
                log::add('MerosSync', 'debug', 'setSpray: '.json_encode($res['result']));
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "lightmode":
                log::add('MerosSync', 'debug', 'call setLightMode with mode '.$channel);
                $res = MerosSync::callMeross('setLightmode', [$eqLogic->getLogicalId(), $channel]);
                log::add('MerosSync', 'debug', 'setLightmode: '.json_encode($res['result']));
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "refresh":
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "up":
                $res = MerosSync::callMeross('goUp', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'mise à jour position '.$res['result']);
                if ($res['result'] != -1)
                {
                  $eqLogic->checkAndUpdateCmd('position', $res['result']);
                }
                break;
            case "down":
                $res = MerosSync::callMeross('goDown', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'mise à jour position '.$res['result']);
                if ($res['result'] != -1)
                {
                  $eqLogic->checkAndUpdateCmd('position', $res['result']);
                }
                break;
            case "stop":
                $res = MerosSync::callMeross('stop', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'mise à jour position '.$res['result']);
                $res = MerosSync::callMeross('syncDevice', [$eqLogic->getLogicalId()]);
                log::add('MerosSync', 'debug', 'refresh: '.json_encode($res['result']));
                MerosSync::syncOneMeross($res['result']);
                break;
            case "changePosition":
                $res = MerosSync::callMeross('setPosition', [$eqLogic->getLogicalId(), $_options['slider']]);
                log::add('MerosSync', 'debug', 'setPosition '.$_options['slider'].': '.$res['result']);
                break;
            default:
                log::add('MerosSync','debug','action: Action='.$action.' '.__('non implementée.', __FILE__));
                break;
        }
    }
}
