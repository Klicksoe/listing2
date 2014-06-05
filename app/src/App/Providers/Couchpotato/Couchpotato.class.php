<?php

use Doctrine\DBAL\Connection;

class Couchpotato{
    private $conn;
 
	// connect to db
    public function __construct(Connection $conn) {
        $this->conn = $conn;
    }
	
	// default function for getting submenu associated to functions
	public static function submenu() {
		return array('index' => 'couchpotato.index', 'last' => 'couchpotato.last');
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