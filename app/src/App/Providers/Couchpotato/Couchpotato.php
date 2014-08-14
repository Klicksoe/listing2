<?php

namespace App\Providers\Couchpotato;

use Doctrine\DBAL\Connection;

class Couchpotato{
    private $conn;
 
	// connect to db
    public function __construct(Connection $conn) {
        $this->conn = $conn;
    }
	
	// default function for getting submenu associated to functions
	public static function submenu() {
		return array('index' => 'couchpotato.index', 'last' => 'couchpotato.last100');
	}
	
	public static function widget($db, $provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE files LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		$stmt = $db->executeQuery('SELECT * FROM `couchpotato` '.$search.' ORDER BY `date` DESC, `name` LIMIT 0,12');
		
		$movies = array();
		while ($movie = $stmt->fetch()) {
			$movies[] = array(
				'title'	=> $movie['name'],
				'id'	=> $movie['imdb'],
				'img'	=> $movie['image'],
				'link'	=> $app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')),
			);
		}
		return $movies;
	}
	
	// fonction associée au sousmenu
	public function index($provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE files LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		
		$stmt = $this->conn->executeQuery('SELECT * FROM `couchpotato` '.$search.' ORDER BY `name`');
		return $app['twig']->render('couchpotato.index.twig', array(
			'focus' => $provider,
			'data' => $stmt->fetchall(),
			'title' => 'couchpotato.index',
		));
	}
	
	// fonction associée au sousmenu
	public function last($provider, $start_path="") {
		global $app;
		global $config;
		
		if (isset($config['providers'][$provider]['start_path']) && !empty($config['providers'][$provider]['start_path'])) {
			$search = ' WHERE files LIKE "'.$config['providers'][$provider]['start_path'].'%"';
		}
		
		$stmt = $this->conn->executeQuery('SELECT * FROM `couchpotato` '.$search.' ORDER BY `date` DESC, `name` LIMIT 0,100');
		return $app['twig']->render('couchpotato.index.twig', array(
			'focus' => $provider,
			'data' => $stmt->fetchall(),
			'title' => 'couchpotato.last',
		));
	}
}