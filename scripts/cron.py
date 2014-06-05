# -*- coding: utf-8 -*-
import sys
# from newsletter import newsletter
from couchupdate import couchupdate
from sickupdate import sickupdate

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
	print couchupdate().updateCouch()
	print sickupdate().updateSick()
if sys.argv[1] == "updatesick":
	print sickupdate().updateSick()
if sys.argv[1] == "updatecouch":
	print couchupdate().updateCouch()
else:
	cmds()
