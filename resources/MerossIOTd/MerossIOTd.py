#!/usr/bin/env python3
import os
import sys
import time
import argparse
import logging
import signal
import threading
import requests
import json
import socketserver
import asyncio

from datetime import datetime
from meross_iot.manager import MerossManager
from meross_iot.http_api import MerossHttpClient
from meross_iot.model.enums import OnlineStatus, ThermostatMode
from meross_iot.model.http.exception import TooManyTokensException, TokenExpiredException, AuthenticatedPostException, HttpApiError, BadLoginException
from meross_iot.controller.mixins.electricity import ElectricityMixin #electricity sensor
from meross_iot.controller.mixins.toggle import ToggleXMixin
from meross_iot.controller.mixins.consumption import ConsumptionXMixin
from meross_iot.controller.mixins.garage import GarageOpenerMixin
from meross_iot.controller.mixins.light import LightMixin
from meross_iot.controller.mixins.thermostat import ThermostatModeMixin, ThermostatState
from meross_iot.controller.mixins.roller_shutter import RollerShutterTimerMixin
from meross_iot.controller.mixins.diffuser_spray import DiffuserSprayMixin
from meross_iot.controller.mixins.diffuser_light import DiffuserLightMixin
from meross_iot.utilities.misc import current_version

http_api_client = 0
manager = 0
thread_run = True
connected = False

# Envoi vers Jeedom ------------------------------------------------------------
class JeedomCallback:
    def __init__(self, apikey, url):
        global thread_run
        thread_run = True
        self.apikey = apikey
        self.url = url
        self.messages = []
        self.t = threading.Thread(target=self.run)
        self.t.setDaemon(True)
        self.t.start()

    def stop(self):
        global thread_run
        logger.debug('Stop thread called')
        thread_run = False

    def send(self, message):
        self.messages.append(message)
        logger.debug('Nouveau message : {}'.format(message))
        logger.debug('Nombre de messages : {}'.format(len(self.messages)))

    def send_now(self, message):
        return self._request(message)

    def run(self):
        global thread_run
        while thread_run:
            while self.messages:
                m = self.messages.pop(0)
                try:
                    self._request(m)
                except Exception as error:
                    logging.error('Erreur envoie requête à jeedom {}'.format(error))
            time.sleep(0.5)
        logging.debug('Fin du thread')

    def _request(self, m):
        response = None
        logger.debug('Envoie à jeedom :  {}'.format(m))
        r = requests.post('{}?apikey={}'.format(self.url, self.apikey), data=json.dumps(m), verify=False)
        if r.status_code != 200:
            logging.error('Erreur envoie requête à jeedom, return code {} - {}'.format(r.status_code, r.reason))
        else:
            response = r.json()
            logger.debug('Réponse de jeedom :  {}'.format(response))
        return response

    def test(self):
        logger.debug('Envoi un test à jeedom')
        r = self.send_now({'action': 'test'})
        if not r or not r.get('success'):
            logging.error('Erreur envoi à jeedom')
            return False
        return True

    def event_handler(self, eventobj):
        logger.debug("Event : {}".format(eventobj.event_type))
        if eventobj.event_type == MerossEventType.DEVICE_SWITCH_STATUS:
            self.send({'action': 'switch', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel_id, 'status':int(eventobj.switch_state)})
        elif eventobj.event_type == MerossEventType.DEVICE_ONLINE_STATUS:
            self.send({'action': 'online', 'uuid':eventobj.device.uuid, 'status':eventobj.status})
        elif eventobj.event_type == MerossEventType.DEVICE_BULB_SWITCH_STATE:
            self.send({'action': 'switch', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel, 'status':int(eventobj.is_on)})
        elif eventobj.event_type == MerossEventType.DEVICE_BULB_STATE:
            self.send({'action': 'bulb', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel, 'status':eventobj.light_state})
        elif eventobj.event_type == MerossEventType.GARAGE_DOOR_STATUS:
            self.send({'action': 'door', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel, 'status':eventobj.door_state})
        #HUMIDIFIER
        elif eventobj.event_type == MerossEventType.HUMIDIFIER_LIGHT_EVENT:
            self.send({'action': 'hlight', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel, 'status':int(eventobj.is_on), 'rgb':int(to_rgb(eventobj.rgb)), 'luminance':eventobj.luminance})
        elif eventobj.event_type == MerossEventType.HUMIDIFIER_SPRY_EVENT:
            self.send({'action': 'hspray', 'uuid':eventobj.device.uuid, 'channel':eventobj.channel, 'status':int(eventobj.spry_mode.value)})
        #ADDITIONS
        elif eventobj.event_type == MerossEventType.CLIENT_CONNECTION:
            self.send({'action': 'connect', 'status':eventobj.status.value})
        elif eventobj.event_type == MerossEventType.DEVICE_BIND:
            self.send({'action': 'bind', 'uuid':eventobj.device.uuid, 'data':eventobj.bind_data})
        elif eventobj.event_type == MerossEventType.DEVICE_UNBIND:
            self.send({'action': 'unbind', 'uuid':eventobj.device.uuid})
        #elif eventobj.event_type == MerossEventType.THERMOSTAT_MODE_CHANGE:
        #elif eventobj.event_type == MerossEventType.THERMOSTAT_TEMPERATURE_CHANGE:

# Reception de Jeedom ----------------------------------------------------------
class JeedomHandler(socketserver.BaseRequestHandler):
    def handle(self):
        # self.request is the TCP socket connected to the client
        logger.debug("Message received in socket")
        data = self.request.recv(1024)
        message = json.loads(data.decode())
        lmessage = dict(message)
        del lmessage['apikey']
        logger.debug(lmessage)
        if message.get('apikey') != _apikey:
            logging.error("Invalid apikey from socket : {}".format(data))
            return
        response = {'result': None, 'success': True}
        action = message.get('action')
        args = message.get('args')
        if hasattr(self, action):
            func = getattr(self, action)
            response['result'] = func
            if callable(response['result']):
                response['result'] = response['result'](*args)
        logger.debug(response)
        try:
            self.request.sendall(json.dumps(response).encode())
        except:
            logger.error("handle Failed: " + str(sys.exc_info()[1]))

    def setOn(self, uuid, channel=0):
        retour=0
        logger.debug("setOn called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetOn(uuid, channel))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setOn Failed: " + str(sys.exc_info()[1]))

    async def aSetOn(self, uuid, channel):
        logger.debug("aSetOn called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetOn connected")
        try:
            logger.debug("aSetOn " + uuid)
            openers = manager.find_devices(device_uuids="["+uuid+"]", device_class=GarageOpenerMixin)
            if len(openers)>0:
                logger.debug("aSetOn - Garage door found")
                dev = openers[0]
                await dev.async_update()
                logger.debug("aSetOn - We open the door")
                await dev.async_open(channel)
                await closeConnection()
                return 1
            else:
                plugs = manager.find_devices(device_uuids="["+uuid+"]")
                if len(plugs) < 1:
                    logger.error("aSetOn - Device not found " + uuid)
                else:
                    logger.debug("aSetOn - Device found")
                    dev = plugs[0]
                    logger.debug("aSetOn - Device update")
                    await dev.async_update()
                    logger.debug("aSetOn - Device turn on")
                    await dev.async_turn_on(channel)
                    await closeConnection()
                    return 1
        except:
            logger.error("aSetOn - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return 0

    def setOff(self, uuid, channel=0):
        retour=0
        logger.debug("setOff called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetOff(uuid, channel))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setOff Failed: " + str(sys.exc_info()[1]))

    async def aSetOff(self, uuid, channel):
        logger.debug("aSetOff called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetOff connected")
        try:
            logger.debug("aSetOff " + uuid)
            openers = manager.find_devices(device_uuids="["+uuid+"]", device_class=GarageOpenerMixin)
            if len(openers)>0:
                logger.debug("aSetOff - Garage door found")
                dev = openers[0]
                await dev.async_update()
                logger.debug("aSetOff - We close the door "+str(channel))
                await dev.async_close(channel)
                await closeConnection()
                return 0
            else:
                plugs = manager.find_devices(device_uuids="["+uuid+"]")
                if len(plugs) < 1:
                    logger.error("aSetOff - Device not found " + uuid)
                else:
                    logger.debug("aSetOff - Device found")
                    dev = plugs[0]
                    logger.debug("aSetOff - Device update")
                    await dev.async_update()
                    logger.debug("aSetOff - Device turn on")
                    await dev.async_turn_off(channel)
                    await closeConnection()
                    return 0
        except:
            logger.error("aSetOff - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return 1

    def goUp(self, uuid):
        logger.debug("goUp called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aMove(uuid,1))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("goUp Failed: " + str(sys.exc_info()[1]))

    def goDown(self, uuid):
        logger.debug("goDown called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aMove(uuid,-1))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("goDown Failed: " + str(sys.exc_info()[1]))

    def stop(self, uuid):
        logger.debug("stop called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aMove(uuid,0))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("stop Failed: " + str(sys.exc_info()[1]))

    async def aMove(self, uuid, sens):
        logger.debug("aMove called "+str(sens))
        global manager
        global args
        await initConnection(args)
        logger.debug("aMove connected")
        try:
            logger.debug("aMove " + str(uuid))
            rollers = manager.find_devices(device_uuids="["+uuid+"]", device_class=RollerShutterTimerMixin)
            if len(rollers)>0:
                logger.debug("aMove - This is a roller")
                dev = rollers[0]
                await dev.async_update()
                if sens==0:
                    logger.debug("aMove - stop")
                    await dev.async_stop(0)
                elif sens==-1:
                    logger.debug("aMove - goDown")
                    await dev.async_close(0)
                elif sens==1:
                    logger.debug("aMove - goUp")
                    await dev.async_open(0)
                await closeConnection()
            else:
                return -1
        except:
            logger.error("aMove - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return -1

    def setSpray(self, uuid, mode):
        logger.debug("setSpray called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetSpray(uuid, mode))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setSpray Failed: " + str(sys.exc_info()[1]))

    async def aSetSpray(self, uuid, mode):
        logger.debug("aSetSpray called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetSpray connected")
        try:
            logger.debug("aSetSpray " + str(uuid) + "-  mode " + str(mode))
            diffs = manager.find_devices(device_uuids="["+uuid+"]", device_class=DiffuserSprayMixin)
            if len(diffs)>0:
                logger.debug("aSetSpray - This is a diffuser spray")
                dev = diffs[0]
                await dev.async_update()
                logger.debug("aSetSpray - We set the mode")
                await dev.async_set_spray_mode(mode,0)
                await closeConnection()
                return "C'est fait - nouveau mode : "+ str(mode)
            else:
                return "Ce n'est pas un diffuseur"
        except:
            logger.error("aSetSpray - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return "Une erreur est survenue"

    def setLumi(self, uuid, lumi_int):
        logger.debug("setLumi called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetLumi(uuid, lumi_int))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setLumi Failed: " + str(sys.exc_info()[1]))

    async def aSetLumi(self, uuid, lumi_int):
        logger.debug("aSetLumi called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetLumi connected")
        try:
            logger.debug("aSetLumi " + uuid)
            lights = manager.find_devices(device_uuids="["+uuid+"]", device_class=LightMixin)
            if len(lights)>0:
                logger.debug("aSetLumi - This is a light")
                dev = lights[0]
                await dev.async_update()
                logger.debug("aSetLumi - We set the luminance")
                await dev.async_set_light_color(0,None,None,lumi_int,None)
                await closeConnection()
                return "C'est fait - nouvelle luminosité : "+ str(lumi_int)
            else:
                diffs = manager.find_devices(device_uuids="["+uuid+"]", device_class=DiffuserLightMixin)
                if len(diffs)>0:
                    logger.debug("aSetLumi - This is a diffuser light")
                    dev = diffs[0]
                    await dev.async_update()
                    logger.debug("aSetLumi - We set the luminance")
                    await dev.async_set_light_mode(channel=0,brightness=lumi_int)
                    await closeConnection()
                    return "C'est fait - nouvelle luminosité : "+ str(lumi_int)
                else:
                    return "Nous ne savons pas gérer cette action"
        except:
            logger.error("aSetLumi - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return "Une erreur est survenue"

    def setTemp(self, uuid, temp_int):
        logger.debug("setTemp called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetTemp(uuid, temp_int))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setTemp Failed: " + str(sys.exc_info()[1]))

    async def aSetTemp(self, uuid, temp_int):
        logger.debug("aSetTemp called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetTemp connected")
        try:
            logger.debug("aSetTemp " + uuid)
            lights = manager.find_devices(device_uuids="["+uuid+"]", device_class=LightMixin)
            if len(lights)>0:
                logger.debug("aSetTemp - This is a light")
                dev = lights[0]
                await dev.async_update()
                logger.debug("aSetTemp - We set the temperature")
                await dev.async_set_light_color(0,None,None,None,temp_int)
                await closeConnection()
                return "C'est fait - nouvelle température : "+ str(temp_int)
            else:
                return "Ce n'est pas une lampe"
        except:
            logger.error("aSetTemp - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return "Une erreur est survenue"

    def setRGB(self, uuid, rgb):
        logger.debug("setRGB called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSetRGB(uuid, rgb))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("setRGB Failed: " + str(sys.exc_info()[1]))

    async def aSetRGB(self, uuid, rgb):
        logger.debug("aSetRGB called")
        global manager
        global args
        await initConnection(args)
        logger.debug("aSetRGB connected")
        try:
            logger.debug("aSetRGB " + uuid)
            lights = manager.find_devices(device_uuids="["+uuid+"]", device_class=LightMixin)
            if len(lights)>0:
                logger.debug("aSetRGB - This is a light")
                dev = lights[0]
                await dev.async_update()
                logger.debug("aSetRGB - We set the color")
                await dev.async_set_light_color(0,None,hex_to_rgb(rgb),None,None)
                await closeConnection()
                return "C'est fait - nouvelle couleur : "+ str(rgb) +" = "+str(hex_to_rgb(rgb))
            else:
                diffs = manager.find_devices(device_uuids="["+uuid+"]", device_class=DiffuserLightMixin)
                if len(diffs)>0:
                    logger.debug("aSetRGB - This is a diffuser light")
                    dev = diffs[0]
                    await dev.async_update()
                    logger.debug("aSetRGB - We set the color")
                    await dev.async_set_light_mode(channel=0,rgb=hex_to_rgb(rgb))
                    await closeConnection()
                    return "C'est fait - nouvelle couleur : "+ str(rgb) +" = "+str(hex_to_rgb(rgb))
                else:
                    return "Nous ne savons pas gérer cette action"
        except:
            logger.error("aSetRGB - Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return "Une erreur est survenue"

    def setSpray(self, uuid, smode=0):
        logger.debug("setSpray called")

    async def aSyncOneMeross(self, device):
        await device.async_update()
        device_online = '0'
        if device.online_status == OnlineStatus.ONLINE:
            device_online = '1'
        d = dict({
            'name': device.name,
            'uuid': device.uuid,
            'famille': str(device.__class__.__name__),
            'online': device_online,
            'type': device.type,
            'ip': device.lan_ip
        })
        d['values'] = {}
        switch = []

        #Récupération des lumières
        lights = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=LightMixin)
        if len(lights) > 0:
            logger.debug("LightMixin")
            light=lights[0]

            d['famille'] = 'GenericBulb'
            onoff = []
            onoff.append('Etat')
            isOn=0
            if light.get_light_is_on():
                isOn=1
            switch.append(isOn)
            d['onoff'] = onoff
            d['values']['switch'] = switch

            if light.get_supports_luminance():
                logger.debug("Support luminance")
                d['lumin']=True
                d['values']['lumival']=light.get_luminance()
            if light.get_supports_rgb():
                logger.debug("Support RGB")
                d['isrgb']=True
                d['values']['rgbval']=rgb_to_hex(light.get_rgb_color())
            if light.get_supports_temperature():
                logger.debug("Support Temperature")
                d['tempe']=True
                d['values']['tempval']=light.get_color_temperature()
        else:
            d['lumin']=False
            d['isrgb']=False
            d['tempe']=False

        #Récupération des consommations instantannées
        plugs = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=ElectricityMixin)
        if len(plugs) > 0:
            logger.debug("ElectricityMixin")
            instant_consumption = await device.async_get_instant_metrics()
            d['elec'] = True
            d['values']['power'] = instant_consumption.power
            d['values']['current'] = instant_consumption.current
            d['values']['tension'] = instant_consumption.voltage
        else:
            d['elec'] = False

        #Récupérations des consommations
        plugs = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=ConsumptionXMixin)
        if len(plugs) > 0:
            logger.debug("ConsumptionXMixin")
            d['conso'] = True
            conso = await device.async_get_daily_power_consumption()
            if len(conso) > 0:
                logger.debug(json.dumps(conso, default=str))
                d['values']['conso_totale'] = 0
                today = datetime.today().strftime("%Y-%m-%d 00:00:00")
                for c in conso:
                    if str(c['date']) == str(today):
                        d['values']['conso_totale'] = float(c['total_consumption_kwh'])
        else:
            d['conso'] = False

        #Récupération des commande volets roulants
        rollers = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=RollerShutterTimerMixin)
        if len(rollers) > 0:
            logger.debug("RollerShutterTimerMixin")
            roller = rollers[0]
            await roller.async_update()
            position = roller.get_position(0)
            d['roller'] = True
            d['values']['position'] = position
        else:
            d['roller'] = False

        #Récupération des diffuseurs huiles essentielles
        diffs = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=DiffuserSprayMixin)
        if len(diffs) > 0:
            logger.debug("DiffuserSprayMixin")
            diff = diffs[0]
            await diff.async_update()
            spray = diff.get_current_spray_mode(0)
            d['spray'] = True
            d['values']['spray'] = "Mode "+str(spray)
            if spray == 0:
                d['values']['spray'] = "Mode léger"
            elif spray == 1:
                d['values']['spray'] = "Mode fort"
            elif spray == 2:
                d['values']['spray'] = "Arrêt"
        else:
            d['spray'] = False

        #Récupération des diffuseurs huiles essentielles - partie lumière
        diffs = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=DiffuserLightMixin)
        if len(diffs) > 0:
            logger.debug("DiffuserLightMixin")
            diff = diffs[0]
            await diff.async_update()
            isOn = diff.get_light_is_on(0)

            d['lumin'] = True
            d['values']['lumival'] = diff.get_light_brightness(0)

            d['isrgb'] = True
            d['values']['rgbval'] = rgb_to_hex(diff.get_light_rgb_color(0))

            lightmode = diff.get_light_mode(0)
            d['lightmode'] = True
            d['values']['lightmode']="Mode "+str(lightmode)
            if lightmode == 0:
                d['values']['lightmode']="Mode multicolor"
            elif lightmode == 1:
                d['values']['lightmode']="Mode fixe"
            elif lightmode == 2:
                d['values']['lightmode']="Mode intensité"
            d['modes'][0]='Mode multicolor'
            d['modes'][1]='Mode fixe'
            d['modes'][2]='Mode intensité'

        else:
            d['lumin'] = False

        #Récupération des thermostats
        therms = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=ThermostatModeMixin)
        if len(therms) > 0:
            logger.debug("ThermostatModeMixin")
            dev = therms[0]
            await dev.async_update()
            therm=dev.get_thermostat_state()
            d['tempe']=True
            d['tempval']=therm.target_temperature_celsius
            d['on']=therm.is_on

            if therm.mode == ThermostatMode.HEAT:
                d['mode'] = 'Mode chauffage'
                d['tempval']=therm.heat_temperature_celsius
            elif therm.mode == ThermostatMode.COOL:
                d['mode'] = 'Mode clim'
                d['tempval']=therm.cool_temperature_celsius
            elif therm.mode == ThermostatMode.ECONOMY:
                d['mode'] = 'Mode eco'
                d['tempval']=therm.eco_temperature_celsius
            elif therm.mode == ThermostatMode.AUTO:
                d['mode'] = 'Mode auto'
                d['tempval']=therm.target_temperature_celsius
            elif therm.mode == ThermostatMode.MANUAL:
                d['mode'] = 'Mode manuel'
                d['tempval']=therm.manual_temperature_celsius
            else:
                d['tempval']=therm.target_temperature_celsius
                d['mode']='Aucun mode'

            if therm.warning:
                d['warning']='Alerte'
            else:
                d['warning']='OK'

            d['minval']=therm.min_temperature_celsius
            d['maxval']=therm.max_temperature_celsius
            d['tempcur']=therm.current_temperature_celsius
        else:
            d['tempe']=False

        #Récupérations des portes de garage
        openers = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=GarageOpenerMixin)
        if len(openers) > 0:
            logger.debug("GarageOpenerMixin")
            device = openers[0]
            onoff = []
            await device.async_update()
            #Gestion multi porte pour un seul device
            if len(device.channels) == 1:
                onoff.append('Etat')
            else:
                onoff.append('Tout')
            channel=0
            while channel<len(device.channels):
                try:
                    if channel > 0:
                        onoff.append(device.channels[channel].name)
                    isOn = 1
                    if device.get_is_open(channel):
                        isOn = 0
                    logger.debug("Channel "+str(channel)+"Is open = "+str(device.get_is_open(channel)))
                    switch.append(isOn)
                except:
                    logger.error("SyncOneMeross Failed: " + str(sys.exc_info()[1]))
                channel = channel + 1

            d['onoff'] = onoff
            d['values']['switch'] = switch
            d['famille'] = 'GenericGarageDoorOpener'
        elif len(lights) < 1:
            #Récupérations des switch si ce n'est pas des portes de garage ni des lumières
            plugs = manager.find_devices(device_uuids="["+device.uuid+"]", device_class=ToggleXMixin)
            if len(plugs) > 0:
                logger.debug("ToggleXMixin")
                onoff = []
                channel=0
                if len(device.channels) == 1:
                    onoff.append('Etat')
                else:
                    onoff.append('Tout')
                while channel<len(device.channels):
                    try:
                        if channel > 0:
                            onoff.append(device.channels[channel].name)
                        isOn = 0
                        if device.is_on(channel):
                            isOn = 1
                        switch.append(isOn)
                    except:
                        logger.error("SyncOneMeross Failed: " + str(sys.exc_info()[1]))
                    channel = channel + 1
                d['onoff'] = onoff
                d['values']['switch'] = switch
            else:
                pass
        return d

    def syncMeross(self):
        retour=0
        logger.debug("syncMeross called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSyncMeross())
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("syncMeross Failed: " + str(sys.exc_info()[1]))

    async def aSyncMeross(self):
        logger.debug("aSyncMeross called")
        global manager
        global args
        await initConnection(args)
        d_devices=[]
        logger.debug("aSyncMeross connected")
        try:
            await manager.async_device_discovery()
            meross_devices = manager.find_devices()
            logger.debug("aSyncMeross - " + str(len(meross_devices)) + " devices found")
            for dev in meross_devices:
                logger.debug("aSyncMeross - " + dev.name + "(" + dev.type + "):" + str(dev.online_status))
                d = await self.aSyncOneMeross(dev)
                d_devices.append(d)
        except:
            logger.error("aSyncMeross Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return d_devices

    def syncDevice(self, uuid):
        retour=0
        logger.debug("syncDevice called")
        try:
            asyncio.set_event_loop(asyncio.new_event_loop())
            self.loop = asyncio.get_event_loop()
            try:
                retour=self.loop.run_until_complete(self.aSyncDevice(uuid))
            finally:
                self.loop.close()
            return retour
        except:
            logger.error("syncDevice Failed: " + str(sys.exc_info()[1]))

    async def aSyncDevice(self, uuid):
        logger.debug("aSyncDevice called")
        global manager
        global args
        await initConnection(args)
        device=0
        logger.debug("aSyncDevice connected")
        try:
            meross_device = manager.find_devices(device_uuids="["+uuid+"]")
            logger.debug("aSyncDevice - " + str(len(meross_device)) + " devices found")
            if (len(meross_device) == 1):
                device = await self.aSyncOneMeross(meross_device[0])
        except:
            logger.error("aSyncDevice Failed: " + str(sys.exc_info()[1]))
        await closeConnection()
        return device

# Les fonctions du daemon ------------------------------------------------------
def convert_log_level(level='error'):
    LEVELS = {'debug': logging.DEBUG,
              'info': logging.INFO,
              'notice': logging.WARNING,
              'warning': logging.WARNING,
              'error': logging.ERROR,
              'critical': logging.CRITICAL,
              'none': logging.NOTSET}
    return LEVELS.get(level, logging.NOTSET)

def handler(signum=None, frame=None):
    logger.debug("Signal %i caught, exiting..." % int(signum))
    shutdown()


async def ashutdown():
    global manager
    global thread_run
    logger.debug("Arrêt")
    thread_run=False
    logger.debug("Stop callback server")
    jc.stop()
    logger.debug("Effacement fichier PID " + str(_pidfile))
    if os.path.exists(_pidfile):
        os.remove(_pidfile)
    logger.debug("Effacement fichier socket " + str(_sockfile))
    if os.path.exists(_sockfile):
        os.remove(_sockfile)
    logger.debug("Exit 0")

def shutdown():
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    loop.run_until_complete(ashutdown())
    loop.close()

def rgb_to_hex(rgb):
    return '#{:02x}{:02x}{:02x}'.format(rgb[0],rgb[1],rgb[2])

def hex_to_rgb(hex):
    rgb = []
    for i in (0, 2, 4):
        decimal = int(hex[i:i+2], 16)
        rgb.append(decimal)
    return tuple(rgb)

# ----------------------------------------------------------------------------
def syncOneElectricity(device):
    # Puissance
    if device.supports_electricity_reading():
        try:
            d = dict({'power': 0,'current': 0,'voltage':0})
            electricity = device.get_electricity()
            d['power'] = float(electricity['power'] / 1000.)
            d['current'] = float(electricity['current'] / 1000.)
            d['voltage'] = float(electricity['voltage'] / 10.)
            return d
        except:
            pass
    # Fini
    return False

async def initConnection(args):
    global manager
    global http_api_client
    global connected
    # Initiates the Meross Cloud Manager. This is in charge of handling the communication with the remote endpoint
    password = args.mpswd.encode().decode('unicode-escape')
    logger.debug("Connecting with user " + args.muser +" & password "+password)
    try:
        http_api_client = await MerossHttpClient.async_from_user_password(api_base_url='https://iotx-eu.meross.com',
                                                                        email=args.muser,
                                                                        password=password)
        logger.debug("Connected with user " + args.muser)
        # Register event handlers for the manager...
        manager = MerossManager(http_client=http_api_client)
        await manager.async_device_discovery()
        connected=True
    except Exception as e:
        print(e)
        logger.error(e)

async def closeConnection():
    global manager
    logger.debug("Close connection")
    manager.close()
    await http_api_client.async_logout()

async def testConnection(args):
    global connected
    await initConnection(args)
    if connected:
        await closeConnection()

# ----------------------------------------------------------------------------
parser = argparse.ArgumentParser()
parser.add_argument('--muser', help='Compte Meross', default='')
parser.add_argument('--mpswd', help='Mot de passe Meross', default='')
parser.add_argument('--callback', help='Jeedom callback', default='http://localhost')
parser.add_argument('--apikey', help='API Key', default='nokey')
parser.add_argument('--loglevel', help='LOG Level', default='error')
parser.add_argument('--pidfile', help='PID File', default='/tmp/MerossIOTd.pid')
parser.add_argument('--errorfile', help='Error File', default='/tmp/MerossIOTderror.pid')
parser.add_argument('--socket', help='Daemon socket', default='/tmp/MerossIOTd.sock')
parser.add_argument('--logfile', help='Log file', default='/tmp/MerosSync.log')
args = parser.parse_args()

# create logger
logger = logging.getLogger('DemonPython')
logger.setLevel(convert_log_level(args.loglevel))
ch = logging.StreamHandler()
ch.setLevel(convert_log_level(args.loglevel))
FORMAT = '[%(asctime)s][%(levelname)s][%(name)s] : %(message)s'
formatter = logging.Formatter(FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
ch.setFormatter(formatter)
logger.addHandler(ch)
logger.propagate = False

loggingLevel = logging.ERROR
if (args.loglevel=='debug'):
    loggingLevel = logging.DEBUG

# create loggerMerossIOT
meross_root_logger = logging.getLogger('meross_iot')
meross_root_logger.setLevel(loggingLevel)
chMeross = logging.StreamHandler()
chMeross.setLevel(loggingLevel)
chMeross.setFormatter(formatter)
meross_root_logger.addHandler(chMeross)
meross_root_logger.propagate = False
meross_root_logger.debug('Test logger merossIOT')

logger.info('Current version is : ' + current_version())
f = open('/var/www/html/plugins/MerosSync/resources/meross-iot_version.txt', 'r')
target_version=f.readline().strip('\n')
f.close()

if (target_version != current_version()):
    logger.error("La version isntallée "+current_version()+" ne correspond pas à la version attendue : "+target_version+". Merci d'installer les dépendances.")
    pid = str(os.getpid())
    logger.debug("Ecriture du PID " + pid + " dans " + str(args.errorfile))
    with open(args.errorfile, 'w') as fp:
        fp.write("%s\n" % pid)
    sys.exit()

logger.info('Start MerossIOTd')
logger.info('Log level : {}'.format(args.loglevel))
logger.info('Socket : {}'.format(args.socket))
logger.info('PID file : {}'.format(args.pidfile))
logger.info('Error file : {}'.format(args.errorfile))
logger.info('Apikey : {}'.format(args.apikey))
logger.info('Callback : {}'.format(args.callback))
logger.info('Python version : {}'.format(sys.version))

_pidfile = args.pidfile
_sockfile = args.socket
_apikey = args.apikey

logger.debug('Mise en place signal')
signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

logger.debug('Test Callback')
jc = JeedomCallback(args.apikey, args.callback)
if not jc.test():
    sys.exit()

logger.debug('Démarrage socket')
if os.path.exists(args.socket):
    os.unlink(args.socket)

server = socketserver.UnixStreamServer(args.socket, JeedomHandler)

logger.debug('Test connection Meross')
asyncio.set_event_loop(asyncio.new_event_loop())
loop = asyncio.get_event_loop()
try:
    loop.run_until_complete(testConnection(args))
finally:
    loop.close()

if connected:
    logger.debug('Test connection Meross ok')

    pid = str(os.getpid())
    logger.debug("Ecriture du PID " + pid + " dans " + str(args.pidfile))
    with open(args.pidfile, 'w') as fp:
        fp.write("%s\n" % pid)

    logger.debug('Ouverture socket')
    t = threading.Thread(target=server.serve_forever())
    t.start()
else:
    pid = str(os.getpid())
    logger.debug("Ecriture du PID " + pid + " dans " + str(args.errorfile))
    with open(args.errorfile, 'w') as fp:
        fp.write("%s\n" % pid)
