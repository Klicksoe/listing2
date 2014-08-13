# -*- coding: utf-8 -*-
import sys, yaml, MySQLdb, urllib2, json, unicodedata, os, logging, logging.handlers

class Couch:

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

	def getAllCouch(self):
		config = self.getConfiguration()
		allcouch = {}
		for (name, provider) in config['providers'].items():
			if provider['type'] == "couchpotato":
				dataprovider = {}
				dataprovider['host'] = str(provider['config']['host'])
				dataprovider['port'] = str(provider['config']['port'])
				dataprovider['api_key'] = str(provider['config']['api_key'])
				dataprovider['basename'] = str(provider['config']['basename'])
				allcouch[dataprovider['host'] + dataprovider['port'] + dataprovider['basename'] + dataprovider['api_key']] = dataprovider
		return allcouch

	def getConfiguration(self):
		stream = file('../config.yml', 'r')
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

	def getAllMovies(self, couchconf):
		stream = urllib2.urlopen('http://' + couchconf['host'] + ':' + couchconf['port'] + couchconf['basename'] + 'api/' + couchconf['api_key'] +                      '/media.list/?release_status=done&status=done&status_or=1&type=movie')
		api = json.load(stream, 'utf8')
		return api['movies']

	def existMovie(self, cursor, id):
		cursor.execute("SELECT count(`id`) as counter FROM `couchpotato` WHERE `imdb`='%s'" % id)
		self.logger.debug('DB test if movie exist: ' + id)
		if cursor.fetchone()[0] > 0:
			return True
		else:
			return False

	def updateMovie(self, cursor, data):
		movie_name = unicode(data['title'])
		movie_syno = unicode(data['info']['plot'])
		movie_iden = unicode(data['_id'])
		movie_imdb = unicode(data['identifiers']['imdb'])

		# define quality and files
		movie_qual = ''
		movie_file = ''
		releases = {}
		if len(data['releases']) > 0:
			for files in data['releases']:
				if 'files' in files:
					movie_qual = unicode(files['quality'])
					for file in files['files']['movie']:
						releases[file] = file
				try:
					movie_note = unicode(data['info']['rating']['imdb'][0])
				except:
					movie_note = 0
		movie_file = ';'.join(releases)
		
		# define poster
		if len(data['info']['images']['poster']) > 0:
			movie_post = unicode(data['info']['images']['poster'][0])
		else:
			movie_post = ''

		# update db
		try:
			cursor.execute("UPDATE `couchpotato` SET `name`=%s, `synopsis`=%s, `_id`=%s, `noteimdb`=%s, `quality`=%s, `image`=%s, `updated`='1', `files`=%s WHERE `imdb`=%s", (movie_name, movie_syno, movie_iden, movie_note, movie_qual, movie_post, movie_file, movie_imdb))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('Update show: ' + movie_name + '(' + movie_imdb + ')')


	def insertMovie(self, cursor, data):
		
		movie_name = unicode(data['title'])
		movie_syno = unicode(data['info']['plot'])
		movie_iden = unicode(data['_id'])
		movie_imdb = unicode(data['identifiers']['imdb'])

		# define quality and files
		movie_qual = ''
		movie_file = ''
		releases = {}
		if len(data['releases']) > 0:
			for files in data['releases']:
				if 'files' in files:
					movie_qual = unicode(files['quality'])
					for file in files['files']['movie']:
						releases[file] = file
				try:
					movie_note = unicode(data['info']['rating']['imdb'][0])
				except:
					movie_note = 0
		movie_file = ';'.join(releases)

		# define poster
		if len(data['info']['images']['poster']) > 0:
			movie_post = unicode(data['info']['images']['poster'][0])
		else:
			movie_post = ''

		# update db
		try:
			cursor.execute("INSERT INTO `couchpotato` (`name`,`synopsis`,`imdb`,`noteimdb`,`quality`,`_id`, `image`, `files`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)", (movie_name, movie_syno, movie_imdb, movie_note, movie_qual, movie_iden, movie_post, movie_file))
		except MySQLdb.Error, e:
			try:
				self.logger.error("MySQL Error [%d]: %s" % (e.args[0], e.args[1]))
			except IndexError:
				self.logger.error("MySQL Error: %s" % str(e))
		self.logger.info('Insert show: ' + movie_name)


	def update(self):
		# DB connection
		db = self.mysqlconnect()
		db.autocommit(True)
		cursor = db.cursor()
		cursor.execute('SET NAMES utf8;')
		cursor.execute('SET CHARACTER SET utf8;')
		cursor.execute('SET character_set_connection=utf8;')

		self.logger.info('Preparation for update')
		cursor.execute("UPDATE `couchpotato` SET `updated`='0'")

		allCouch = self.getAllCouch()
		for (name, couchconf) in allCouch.items():
			allmovies = self.getAllMovies(couchconf)	
			for movie in allmovies:
				if self.existMovie(cursor, movie['identifiers']['imdb']):
					self.updateMovie(cursor, movie)
				else:
					self.insertMovie(cursor, movie)
	
		self.logger.info('Clear old movies')
		cursor.execute("DELETE FROM `couchpotato` WHERE `updated`='0'")
		
		if cursor:
			self.logger.info('DB close')
			cursor.close()
			db.close()

