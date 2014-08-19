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
	public static function submenu($provider) {
		global $config;
		$submenu = array('index' => 'couchpotato.index', 'last' => 'couchpotato.last100');
		if (isset($config['providers'][$provider]['allowadd']) && $config['providers'][$provider]['allowadd'] == True) {
			$submenu['addmovie'] = 'couchpotato.addmovie';
		}
		return $submenu;
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
	
	public function addmovie($provider) {
		global $app;
		global $config;
		
		$returncode = '';
		if (isset($_GET['add']) && preg_match("/tt\d{7}/", $_GET['add'])) {
			$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'api/'.$config['providers'][$provider]['config']['api_key'].'/movie.add/?identifier='.$_GET['add'];
			$api = file_get_contents($apiurl);
			$json = json_decode($api, true);
			if (isset($json['success']) && $json['success'] == 'true') {
				$returncode = $json['movie']['info']['original_title'];
			} else {
				$returncode = false;
			}
		}
		
		if (isset($_GET['search']) && !empty($_GET['search'])) {
			$apiurl = 'http://'.$config['providers'][$provider]['config']['host'].':'.$config['providers'][$provider]['config']['port'].$config['providers'][$provider]['config']['basename'].'api/'.$config['providers'][$provider]['config']['api_key'].'/search/?q='.urlencode($_GET['search']);
			$api = file_get_contents($apiurl);
			$data = json_decode($api, true);
			$search = $_GET['search'];
		} else {
			$search = '';
			$data = '';
		}
		
		return $app['twig']->render('couchpotato.add.twig', array(
			'returncode'=> $returncode,
			'focus'		=> $provider,
			'search'	=> $search,
			'data'		=> $data,
			'title'		=> 'couchpotato.addmovie',
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
			'title' => 'couchpotato.last100',
		));
	}
}
