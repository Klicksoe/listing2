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
	public static function submenu() {
		return array('index' => 'sickbeard.index', 'last' => 'sickbeard.last');
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
				'id'	=> $episode['season'].'x'.$episode['episode'],
				'img'	=> $app['url_generator']->generate('base').'assets/sickbeard/banner.'.$episode['_id'].'.jpg',
				'link'	=> $app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'show')).'?id='.$episode['_id'],
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
			'title' => 'sickbeard.last',
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
			'focus' => $provider,
			'show' => $stmt->fetch(),
			'episodes' => $episodes,
		));
	}
	
}