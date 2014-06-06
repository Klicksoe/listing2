# -*- coding: utf-8 -*-
import sys, yaml, MySQLdb, urllib2, json, unicodedata

class couchupdate:
	def __init__(self):
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
		except MySQLdb.Error, e:
			try:
				print "MySQL Error [%d]: %s" % (e.args[0], e.args[1])
			except IndexError:
				print "MySQL Error: %s" % str(e)
			sys.exit(1)
		finally:
			return db

	def updateCouch(self):
		allcouch = self.getAllCouch()
		for (name, couchconf) in allcouch.items():
			stream = urllib2.urlopen('http://' + couchconf['host'] + ':' + couchconf['port'] + couchconf['basename'] + 'api/' + couchconf['api_key'] + 			'/media.list/?release_status=done&status=done&status_or=1&type=movie')
			# stream = unicode(stream.read())
			api = json.load(stream, 'utf8')
			db = self.mysqlconnect()
			db.autocommit(True)
			cursor = db.cursor()
			cursor.execute("UPDATE `couchpotato` SET `updated`='0'")
			if api['success']:
				for movie in api['movies']:
					if self.checkmovieexist(movie['identifiers']['imdb']):
						try:
							print "Update... " + movie['title'].encode('utf8')
							if len(movie['info']['images']['poster']) > 0:
								poster = movie['info']['images']['poster'][0]
							else:
								poster = ""
							quality = ""
							releases = ""
							if len(movie['releases']) > 0:
								for files in movie['releases']:
									if 'files' in files:
										quality = file['quality']
										for file in files['files']['movie']:
											releases = releases + file + ';'
										releases = releases[:-1]
										try:
											rating = movie['info']['rating']['imdb'][0]
										except:
											rating = 0								
										cursor.execute("UPDATE `couchpotato` SET `name`=%s, `synopsis`=%s, `_id`=%s, `noteimdb`=%s, `quality`=%s, `image`=%s, `updated`='1', `files`=%s WHERE `imdb`=%s", (movie['title'], movie['info']['plot'], movie['_id'], str(rating), quality, poster, releases, movie['identifiers']['imdb']))
						except MySQLdb.Error, e:
							try:
								print "MySQL Error [%d]: %s" % (e.args[0], e.args[1])
							except IndexError:
								print "MySQL Error: %s" % str(e)
							cursor.close()
							db.close()
							sys.exit(1)
					else:
						try:
							print "Add... " + movie['title'].encode('utf8')
							if len(movie['info']['images']['poster']) > 0:
								poster = movie['info']['images']['poster'][0]
							else:
								poster = ""
							files = ""
							if len(movie['releases']) > 0:
								quality = movie['releases'][0]['quality']
								for file in movie['releases'][0]['files']['movie']:
									files = files + file + ';'
								files = files[:-1]
								try:
									rating = movie['info']['rating']['imdb'][0]
								except:
									rating = 0			
								cursor.execute("INSERT INTO `couchpotato` (`name`,`synopsis`,`imdb`,`noteimdb`,`quality`,`_id`, `image`, `files`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)", (movie['title'], movie['info']['plot'], movie['identifiers']['imdb'], str(rating), quality, movie['_id'], poster, files))
						except MySQLdb.Error, e:
							try:
								print "MySQL Error [%d]: %s" % (e.args[0], e.args[1])
							except IndexError:
								print "MySQL Error: %s" % str(e)
							cursor.close()
							db.close()
							sys.exit(1)
			cursor.execute("DELETE FROM `couchpotato` WHERE `updated`='0'")
			if cursor:
				cursor.close()
				db.close()
		return True

	def checkmovieexist(self, id):
		db = self.mysqlconnect()
		cursor = db.cursor()
		cursor.execute("SELECT count(`id`) as counter FROM `couchpotato` WHERE `imdb`=%s", id.encode('utf8'))
		rows = cursor.fetchone()
		db.close()
		if rows[0] > 0:
			return True
		else:
			return False
			