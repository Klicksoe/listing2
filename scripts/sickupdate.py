# -*- coding: utf-8 -*-
import sys, yaml, MySQLdb, urllib2, json, unicodedata, os

class sickupdate:
	def __init__(self):
		pass
	
	def getAllSick(self):
		config = self.getConfiguration()
		allsick = {}
		for (name, provider) in config['providers'].items():
			if provider['type'] == "sickbeard":
				dataprovider = {}
				dataprovider['host'] = str(provider['config']['host'])
				dataprovider['port'] = str(provider['config']['port'])
				dataprovider['api_key'] = str(provider['config']['api_key'])
				dataprovider['basename'] = str(provider['config']['basename'])
				allsick[name] = dataprovider
		return allsick
	
	def getConfiguration(self):
		stream = file('../config.yml', 'r')
		data = yaml.load(stream)
		return data
		
	def mysqlconnect(self):
		config = self.getConfiguration()
		try:
			db = MySQLdb.connect(host=config['database']['host'], user=config['database']['user'], passwd=config['database']['pass'], db=config['database']['base'], charset="utf8")
		except MySQLdb.Error, e:
			try:
				print "MySQL Error [%d]: %s" % (e.args[0], e.args[1])
			except IndexError:
				print "MySQL Error: %s" % str(e)
			sys.exit(1)
		finally:
			return db

	def updateSick(self):
		allsick = self.getAllSick()
		for (name, sickconf) in allsick.items():
			stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=shows')
			# stream = unicode(stream.read())
			api = json.load(stream, 'utf8')
			db = self.mysqlconnect()
			db.autocommit(True)
			cursor = db.cursor()
			cursor.execute("UPDATE `sickbeard` SET `updated`='0'")
			cursor.execute("UPDATE `sickbeard_episodes` SET `updated`='0'")
			if not os.path.exists('../web/assets/sickbeard'):
				os.makedirs('../web/assets/sickbeard')
			if api['result']=='success':
				for idshow in api['data']:
					streamshow = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show&tvdbid='+idshow)
					apishow = json.load(streamshow, 'utf8')
					if self.checkshowexist(idshow):
						print "Update... " + apishow['data']['show_name']
						cursor.execute("UPDATE `sickbeard` SET `name`=%s, `path`=%s, `updated`='1' WHERE `_id`=%s", (apishow['data']['show_name'], apishow['data']['location'], idshow))
					else:
						print "Add... " + apishow['data']['show_name']
						cursor.execute("INSERT INTO `sickbeard`(`_id`, `name`, `path`)  VALUES(%s, %s, %s)", (idshow, apishow['data']['show_name'], apishow['data']['location']))
					bannershow = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show.getbanner&tvdbid='+idshow)
					if os.path.isfile('../web/assets/sickbeard/banner.' + idshow + '.jpg'):
						os.remove('../web/assets/sickbeard/banner.' + idshow + '.jpg')
					banner = open('../web/assets/sickbeard/banner.' + idshow + '.jpg', 'wb')
					banner.write(bannershow.read())
					banner.close()
					
					
					streamepisode = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show.seasons&tvdbid='+idshow)
					apiepisode = json.load(streamepisode, 'utf8')
					for season in apiepisode['data']:
						for episode in apiepisode['data'][season]:
							if apiepisode['data'][season][episode]['status'] == 'Downloaded':
								streamepisodefull = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=episode&tvdbid='+idshow+'&season='+season+'&episode='+episode+'&full_path=1')
								apiepisodefull = json.load(streamepisodefull, 'utf8')
								if self.checkepisodeexist(idshow, season, episode):
									# print "Update... Episode S" + season + "E" + episode
									cursor.execute("UPDATE `sickbeard_episodes` SET `name`=%s, `path`=%s, `updated`='1' WHERE `_id`=%s AND `season`=%s AND `episode`=%s", (apiepisodefull['data']['name'], apiepisodefull['data']['location'], int(idshow), int(season), int(episode)))
								else:
									# print "Add... Episode S" + season + "E" + episode
									cursor.execute("INSERT INTO `sickbeard_episodes`(`_id`, `season`, `episode`, `name`, `path`)  VALUES(%s, %s, %s, %s, %s)", (int(idshow), int(season), int(episode), apiepisodefull['data']['name'], apiepisodefull['data']['location']))
									
							
			cursor.execute("DELETE FROM `sickbeard` WHERE `updated`='0'")
			cursor.execute("DELETE FROM `sickbeard_episodes` WHERE `updated`='0'")
			if cursor:
				cursor.close()
				db.close()
		return True

	def checkshowexist(self, id):
		db = self.mysqlconnect()
		cursor = db.cursor()
		cursor.execute("SELECT count(`id`) as counter FROM `sickbeard` WHERE `_id`=%s", id.encode('utf8'))
		rows = cursor.fetchone()
		db.close()
		if rows[0] > 0:
			return True
		else:
			return False

	def checkepisodeexist(self, id, season, episode):
		db = self.mysqlconnect()
		cursor = db.cursor()
		cursor.execute("SELECT count(`id`) as counter FROM `sickbeard_episodes` WHERE `_id`=%s AND `season`=%s AND `episode`=%s", (id, season, episode))
		rows = cursor.fetchone()
		db.close()
		if rows[0] > 0:
			return True
		else:
			return False
			