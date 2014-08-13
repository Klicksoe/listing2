# -*- coding: utf-8 -*-
import sys, yaml, MySQLdb, urllib2, json, unicodedata, os, logging, logging.handlers

class Sick:

	def __init__(self):
		logging.basicConfig(
			level=logging.INFO,
			format='%(asctime)s %(name)-12s %(levelname)-8s %(message)s',
			datefmt='%m-%d %H:%M',
			filename='Log.log',
                    	filemode='w'
		)

		logformat = logging.Formatter('%(asctime)s %(name)-12s %(levelname)-8s %(message)s')
		logfile = logging.handlers.TimedRotatingFileHandler('Log.log', when='midnight', backupCount=7)
		logfile.setFormatter(logformat)
		self.logger = logging.getLogger(__name__)
		self.logger.addHandler(logfile)

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
				allsick[dataprovider['host'] + dataprovider['port'] + dataprovider['basename'] + dataprovider['api_key']] = dataprovider
		return allsick

	def getConfiguration(self):
		stream = file('../listing2/config.yml', 'r')
		data = yaml.load(stream)
		return data

	def mysqlconnect(self):
		config = self.getConfiguration()
		try:
			db = MySQLdb.connect(host=config['database']['host'], user=config['database']['user'], passwd=config['database']['pass'], db=config['database']['base'], charset="utf8")
			self.logger.info('Connect to database')
			db.set_character_set('utf8')
		except MySQLdb.Error, e:
			self.logger.error("MySQL Error [%(date)s]: %(message)s" % {'date':e.args[0], 'message':e.args[1]})
			sys.exit(1)
		except IndexError, e:
			self.logger.error("MySQL Error: %(message)s" % {'message': str(e)})
			sys.exit(1)
		finally:
			return db

	def getBanner(self, sickconf, id):
		stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show.getbanner&tvdbid='+id)
		if os.path.isfile('../web/assets/sickbeard/banner.' + id + '.jpg'):
			os.remove('../web/assets/sickbeard/banner.' + id + '.jpg')
			banner = open('../web/assets/sickbeard/banner.' + id + '.jpg', 'wb')
			banner.write(stream.read())
			banner.close()

	def getAllIDSeries(self, sickconf):
		allidseries = {}
		stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=shows')
		api = json.load(stream, 'utf8')
		self.logger.debug('API call "shows"')
		for id in api['data']:
			allidseries[id] = id
		return allidseries
				

	def getSerie(self, sickconf, id):
		stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show&tvdbid='+id)
		api = json.load(stream, 'utf8')
		self.logger.debug('API call "show" for show: ' + id)
		return api['data']

	def getEpisodes(self, sickconf, id):
		stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=show.seasons&tvdbid='+id)
		api = json.load(stream, 'utf8')
		self.logger.debug('API call "show.seasons" for show: ' + id)
		return api['data']

	def getEpisode(self, sickconf, id, season, episode):
		stream = urllib2.urlopen('http://' + sickconf['host'] + ':' + sickconf['port'] + sickconf['basename'] + 'api/' + sickconf['api_key'] + '/?cmd=episode&tvdbid='+id+'&season='+season+'&episode='+episode+'&full_path=1')
		api = json.load(stream, 'utf8')
		self.logger.debug('API call "episode" for show: ' + id + ' (' + season + 'x' + episode + ')')
		return api['data']

	def existSerie(self, cursor, id):
		cursor.execute("SELECT count(`id`) as counter FROM `sickbeard` WHERE `_id`='%s'" % id)
		self.logger.debug('DB test if show exist: ' + id)
		if cursor.fetchone()[0] > 0:
			return True
		else:
			return False

	def existEpisode(self, cursor, id, season, episode):
		cursor.execute("SELECT count(`id`) as counter FROM `sickbeard_episodes` WHERE `_id`='%s' AND `season`='%s' AND `episode`='%s'" % (id, season, episode))
		self.logger.debug('DB test if episode exist:' + id + '(' + season + 'x' + episode + ')')
		if cursor.fetchone()[0] > 0:
			return True
		else:
			return False


	def updateSerie(self, cursor, id, data):
		serie_name = unicode(data['show_name'])
		serie_path = unicode(data['location'])
		try:
			cursor.execute("UPDATE `sickbeard` SET `name`=%s, `path`=%s, `updated`='1' WHERE `_id`=%s", (serie_name, serie_path, id))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('Update show: ' + serie_name + '(' + id + ')')

	def insertSerie(self, cursor, id, data):
		serie_name = unicode(data['show_name'])
		serie_path = unicode(data['location'])
		try:
			cursor.execute("INSERT INTO `sickbeard`(`_id`, `name`, `path`)  VALUES(%s, %s, %s)", (id, serie_name, serie_path))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('Insert show: ' + serie_name + '(' + id + ')')

	def updateEpisode(self, cursor, id, season, episode, data):
		episode_name = unicode(data['name'])
		episode_path = unicode(data['location'])
		try:
			cursor.execute("UPDATE `sickbeard_episodes` SET `name`=%s, `path`=%s, `updated`='1' WHERE `_id`=%s AND `season`=%s AND `episode`=%s", (episode_name, episode_path, id, season, episode))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('    Update S'+season+'E'+episode+' - '+data['name'])

	def insertEpisode(self, cursor, id, season, episode, data):
		episode_name = unicode(data['name'])
		episode_path = unicode(data['location'])
		try:
			cursor.execute("INSERT INTO `sickbeard_episodes`(`_id`, `season`, `episode`, `name`, `path`)  VALUES(%s, %s, %s, %s, %s)", (id, season, episode, episode_name, episode_path))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('    Add S'+season+'E'+episode+' - '+data['name'])


	def update(self):
		# DB connection
		db = self.mysqlconnect()
		db.autocommit(True)
		cursor = db.cursor()
		cursor.execute('SET NAMES utf8;')
		cursor.execute('SET CHARACTER SET utf8;')
		cursor.execute('SET character_set_connection=utf8;')

		self.logger.info('Preparation for update')
		cursor.execute("UPDATE `sickbeard` SET `updated`='0'")
		cursor.execute("UPDATE `sickbeard_episodes` SET `updated`='0'")

		if not os.path.exists('../web/assets/sickbeard'):
			os.makedirs('../web/assets/sickbeard')

		allSick = self.getAllSick()
		for (name, sickconf) in allSick.items():
			allid = self.getAllIDSeries(sickconf)
			for (id, idshow) in allid.items():
				self.getBanner(sickconf, idshow)
				serie = self.getSerie(sickconf, idshow)
				if self.existSerie(cursor, idshow):
					self.updateSerie(cursor, idshow, serie)
				else:
					self.insertSerie(cursor, idshow, serie)
				listepisodes = self.getEpisodes(sickconf, id)
				for season in listepisodes:
					for episode in listepisodes[season]:
						if listepisodes[season][episode]['status'] == "Downloaded":
							if self.existEpisode(cursor, idshow, season, episode):
								self.updateEpisode(cursor, idshow, season, episode, self.getEpisode(sickconf, idshow, season, episode))
							else:
								self.insertEpisode(cursor, idshow, season, episode, self.getEpisode(sickconf, idshow, season, episode))
		
		self.logger.info('Clear old episodes and shows')
		cursor.execute("DELETE FROM `sickbeard` WHERE `updated`='0'")
		cursor.execute("DELETE FROM `sickbeard_episodes` WHERE `updated`='0'")	
		if cursor:
			self.logger.info('DB close')
			cursor.close()
			db.close()

