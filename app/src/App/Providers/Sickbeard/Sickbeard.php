<?php

namespace App\Providers\Sickbeard;

use Doctrine\DBAL\Connection;

class Sickbeard {
    private $conn;
 
	// connect to db
    public function __construct(Connection $conn) {
        $this->conn = $conn;
    }
	
	// default function for getting submenu associated to functions
	public static function submenu($provider) {
		global $config;
		$submenu = array('index' => 'sickbeard.index', 'last' => 'sickbeard.last100');
		if (isset($config['providers'][$provider]['allowadd']) && $config['providers'][$provider]['allowadd'] == True) {
			$submenu['addshow'] = 'sickbeard.addshow';
		}
		return $submenu;
	}
	
	public static function widget($db, $provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE se.`path` LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		$stmt = $db->executeQuery('SELECT s.name, se.season, se.episode, se._id FROM `sickbeard_episodes` se LEFT JOIN `sickbeard` as s ON s._id=se._id '.$search.' ORDER BY `date` DESC LIMIT 0,12');
		$episodes = array();
		while ($episode = $stmt->fetch()) {
			$episodes[] = array(
				'title'	=> $episode['name'].' '.$episode['season'].'x'.$episode['episode'],
				'id'	=> $episode['_id'].'-'.$episode['season'].'x'.$episode['episode'],
				'img'	=> $app['url_generator']->generate('base').'assets/sickbeard/poster.'.$episode['_id'].'.jpg',
				'link'	=> $app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'last')),
			);
		}
		return $episodes;
		return true;
	}
	
	// fonction associée au sousmenu
	public function index($provider, $start_path="") {
		global $app;
		global $config;
		
		$search = '';
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE `path` LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		
		$stmt = $this->conn->executeQuery('SELECT * FROM `sickbeard` '.$search.' ORDER BY `name`');
		return $app['twig']->render('sickbeard.index.twig', array(
			'focus' => $provider,
			'data' => $stmt->fetchall(),
			'title' => 'sickbeard.index',
		));
	}
	
	
	public function reload($provider) {
		global $config;
		
		$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'/home/postprocess/processEpisode?quiet=1';
		$api = file_get_contents($apiurl);
		return 'sickbeard';
	}
	
	public function addshow($provider) {
		global $app;
		global $config;
		
		if ($config['providers'][$provider]['allowadd'] !== (int)1) {
			return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
		}
		
		$returncode = '';
		$langid = '';
		
		if (isset($_GET['add']) && preg_match("/\d/", $_GET['add'])) {
			$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'api/'.$config['providers'][$provider]['config']['api_key'].'/?cmd=show.addnew&tvdbid='.$_GET['add'];
			$api = file_get_contents($apiurl);
			$json = json_decode($api, true);
			if (isset($json['result']) && $json['result'] == 'success') {
				$returncode = $json['data']['name'];
			} else {
				$returncode = false;
			}
		}
		
		if (isset($_GET['search']) && !empty($_GET['search'])) {
			$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'home/addShows/searchTVDBForShowName?name='.urlencode($_GET['search']);
			$api = file_get_contents($apiurl);
			$json = json_decode($api, true);
			$data = array();
			$langid = $json['langid'];
			
			// get actual tv show from api
			$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'api/'.$config['providers'][$provider]['config']['api_key'].'/?cmd=shows';
			$api = file_get_contents($apiurl);
			$actualshow = json_decode($api, true);
			$allshows = array();
			foreach($actualshow['data'] as $aid => $ashow) {
				$allshow[$aid] = $aid;
			}
			
			foreach($json['results'] as $show) {
				if (!in_array($show[0], $allshow)) {
					$data[] = array(
						'tvdbid'	=> $show[0],
						'name'		=> $show[1],
						'data'		=> $show[2],
					);
				}
			}
			
			
			$search = $_GET['search'];
		} else {
			$search = '';
			$data = '';
		}
		
		return $app['twig']->render('sickbeard.add.twig', array(
			'returncode'=> $returncode,
			'focus'		=> $provider,
			'search'	=> $search,
			'data'		=> $data,
			'title'		=> 'sickbeard.addshow',
			'langid'	=> $langid,
		));
	}
	
	// fonction associée au sousmenu
	public function last($provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE `path` LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		
		$stmt = $this->conn->executeQuery('SELECT e.*, s.name as showname FROM (SELECT * FROM `sickbeard_episodes` '.$search.' ORDER BY `date` DESC LIMIT 0,100) e LEFT JOIN `sickbeard` s ON s._id = e._id ORDER BY e.`date` DESC');
		return $app['twig']->render('sickbeard.last.twig', array(
			'focus' => $provider,
			'data' => $stmt->fetchall(),
			'title' => 'sickbeard.last100',
		));
	}
	
	public function show($provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' AND `path` LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		
		$stmt = $this->conn->prepare('SELECT * FROM `sickbeard` WHERE `_id`=:idshow '.$search);
		$stmt->bindValue('idshow', $_GET['id']);
		$stmt->execute();
		
		$stmtep = $this->conn->prepare('SELECT * FROM `sickbeard_episodes` WHERE `_id`=:idshow '.$search.' ORDER BY `season`, `episode` ASC');
		$stmtep->bindValue('idshow', $_GET['id']);
		$stmtep->execute();
		
		$episodes = array();
		while ($row = $stmtep->fetch()) {
			$episodes[$row['season']][$row['episode']] = $row;
		}
		
		return $app['twig']->render('sickbeard.show.twig', array(
			'focus' 	=> $provider,
			'show' 		=> $stmt->fetch(),
			'episodes' 	=> $episodes,
		));
	}
	
}
