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
function MerossIOT2_install()
{
    message::removeAll('MerossIOT2');
    message::add('MerossIOT2', '{{Installation du plugin MerossIOT2 terminée}}''.', null, null);
}
/**
 * Jeedom plugin update function.
 */
function MerossIOT2_update()
{
    log::add('MerossIOT2', 'debug', 'MerossIOT2_update');
    $daemonInfo = MerossIOT2::deamon_info();
    if( $daemonInfo['state'] == 'ok' ) {
        MerossIOT2::deamon_stop();
    }
    $cache = cache::byKey('dependancy' . 'MerossIOT2');
    $cache->remove();
    MerossIOT2::dependancy_install();
    message::removeAll('MerossIOT2');
    message::add('MerossIOT2', '{{Mise à jour du plugin MerossIOT2 terminée}}''.', null, null);
    MerossIOT2::deamon_start();
}
/**
 * Jeedom plugin remove function.
 */
function MerossIOT2_remove()
{
    log::add('MerossIOT2', 'debug', 'MerossIOT2_remove');
    $daemonInfo = MerossIOT2::deamon_info();
    if( $daemonInfo['state'] == 'ok' ) {
        MerossIOT2::deamon_stop();
    }
    message::removeAll('MerossIOT2');
    message::add('MerossIOT2', '{{Désinstallation du plugin MerossIOT2 terminée}}''.', null, null);
}
