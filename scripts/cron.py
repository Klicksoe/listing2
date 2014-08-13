# -*- coding: utf-8 -*-
import sys
from Couch import Couch
from Sick import Sick

def cmds():
	print("commandes")
	print("########################################")
	print("cron.py update : update locally couchpotato and sickbeard")
	print("cron.py updatecouch : update locally couchpotato")
	print("cron.py updatesick : update locally sickbeard")
	

if len(sys.argv) != 2:
	cmds()
	sys.exit()

	
if sys.argv[1] == "update":
	Couch().update()
	Sick().update()
else if sys.argv[1] == "updatesick":
	Sick().update()
else if sys.argv[1] == "updatecouch":
	couch().update()
else:
	cmds()
