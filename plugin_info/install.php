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
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
/**
 * Jeedom plugin installation function.
 */
function MerosSync_install()
{
    message::removeAll('MerosSync');
    message::add('MerosSync', '{{Installation du plugin MerosSync terminée}}''.', null, null);
}
/**
 * Jeedom plugin update function.
 */
function MerosSync_update()
{
    log::add('MerosSync', 'debug', 'MerosSync_update');
    $daemonInfo = MerosSync::deamon_info();
    if( $daemonInfo['state'] == 'ok' ) {
        MerosSync::deamon_stop();
    }
    $cache = cache::byKey('dependancy' . 'MerosSync');
    $cache->remove();
    MerosSync::dependancy_install();
    message::removeAll('MerosSync');
    message::add('MerosSync', '{{Mise à jour du plugin MerosSync terminée}}''.', null, null);
    MerosSync::deamon_start();
}
/**
 * Jeedom plugin remove function.
 */
function MerosSync_remove()
{
    log::add('MerosSync', 'debug', 'MerosSync_remove');
    $daemonInfo = MerosSync::deamon_info();
    if( $daemonInfo['state'] == 'ok' ) {
        MerosSync::deamon_stop();
    }
    message::removeAll('MerosSync');
    message::add('MerosSync', '{{Désinstallation du plugin MerosSync terminée}}''.', null, null);
}
