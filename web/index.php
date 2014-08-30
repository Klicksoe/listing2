<?php

require_once __DIR__.'/../vendor/autoload.php';

use App\Providers\Sickbeard;
use App\Providers\Couchpotato;

# Define silex application
$app = new Silex\Application(); 
$app['debug'] = true;

# Parse the configuration file
try {
	$yml = new Symfony\Component\Yaml\Parser();
	$config = $yml->parse(file_get_contents('../config.yml'));
} catch (Symfony\Component\Yaml\Exception\ParseException $e) {
	printf("Unable to parse the YAML string: %s", $e->getMessage());
}

# Connection to DB
$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver'	=> 'pdo_mysql',
        'dbhost'	=> $config['database']['host'],
        'dbname'	=> $config['database']['base'],
        'user'		=> $config['database']['user'],
        'password'	=> $config['database']['pass'],
        'charset'	=> 'UTF8',
    ),
));

# Add Session support
$app->register(new Silex\Provider\SessionServiceProvider());

# Add URL generator
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

# Add security provider
$app->register(new Silex\Provider\SecurityServiceProvider());
$app['security.firewalls'] = array(
    'default' => array(
		'pattern' 	=> '^/',
		'anonymous' => array(),
		'form'		=> array(
			'login_path' 						=> '/login',
			'check_path' 						=> '/admin/login_check',
			'default_target_path' 				=> '/admin/dashboard',
			'always_use_default_target_path' 	=> true,
			'username_parameter' 				=> '_username',
			'password_parameter' 				=> '_password',
			'failure_path' 						=> '/login',
		),
		'logout'	=> array(
			'logout_path'			=> '/admin/logout',
			'target' 				=> '/',
			'invalidate_session'	=> true,
		),
		'users'		=> $app->share(function() use ($app) {
			return new App\User\UserProvider($app['db']);
		}),
	),
);
$app['security.access_rules'] = array(
    array('^/admin', 'ROLE_ADMIN'),
);

# Add twig support
$app->register(new Silex\Provider\TwigServiceProvider(), array(
	'twig.path' 	=> __DIR__ . '/../app/views/'.$config['site']['theme'],
));

# generate menu
$menu = array();
foreach($config['providers'] as $key => $value) {
	if ($value['type'] == 'link') {
		$menu[$key] = array(
			'name'		=> $value['title'],
			'submenu'	=> false,
			'link'		=> $value['link'],
		);
	} else {
		switch ($value['type']) {
			case 'couchpotato':
				$class = new Couchpotato\Couchpotato($app['db']);
				break;
			case 'sickbeard':
				$class = new Sickbeard\Sickbeard($app['db']);
				break;
		}
		$menu[$key] = array(
			'name'		=> $value['title'],
			'submenu'	=> $class::submenu($key),
			'link'		=> false,
		);
	}
}

# conf from db
$dbconf = array();
$stmt = $app['db']->executeQuery("SELECT * FROM `config`");
while ($data = $stmt->fetch()) {
	$dbconf[$data['name']] = $data['value'];
}

# Add global variables on twig
$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
	global $config;
	global $menu;
	global $dbconf;
    $twig->addGlobal('config', $config);
    $twig->addGlobal('menu', $menu);
    $twig->addGlobal('dbconf', $dbconf);
    return $twig;
}));

# Add translation provider
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
	'locale'			=> substr(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0], 0, 2),
    'locale_fallback' 	=> 'en',
));

# Add global translation
$app['translator'] = $app->share($app->extend('translator', function($translator, $app) {
	$translator->addLoader('yaml', new Symfony\Component\Translation\Loader\YamlFileLoader());
	$dh  = opendir('../app/locales/');
	while (false !== ($localefile = readdir($dh))) {
		if ($localefile != '.' && $localefile != '..') {
			$translator->addResource('yaml', __DIR__.'/../app/locales/'.$localefile, explode('.', $localefile)[0]);
		}
	}
	return $translator;
}));

#################################################################################################
#										404														#
#################################################################################################
// $app->error(function (\Exception $e, $code) {
	// global $app;
    // switch ($code) {
        // case 404:
			// return $app->redirect($app['url_generator']->generate('404'));
            // break;
        // default:
            // $message = 'We are sorry, but something went terribly wrong.';
    // }

    // return new Symfony\Component\HttpFoundation\Response($message);
// });


#################################################################################################
#										Routing													#
#################################################################################################
$app->get('/', function() use ($app) {
	return $app->redirect($app['url_generator']->generate('index'));
})->bind('base');

$app->get('/index', function() use ($app) {
	global $config;
	
	$widgets = array();
	foreach($config['providers'] as $key => $value) {
		switch ($value['type']) {
			case 'couchpotato':
				$class = new Couchpotato\Couchpotato($app['db']);
				$widget = $class::widget($app['db'], $key, $value['start_path']);
				$widgets[$key] = array(
					'name'		=> $value['title'],
					'widget'	=> $widget,
				);
				break;
			case 'sickbeard':
				$class = new Sickbeard\Sickbeard($app['db']);
				$widget = $class::widget($app['db'], $key, $value['start_path']);
				$widgets[$key] = array(
					'name'		=> $value['title'],
					'widget'	=> $widget,
				);
				break;
		}
	}
	
	return $app['twig']->render('index.twig', array(
		'focus' 	=> 'index',
		'widgets'	=> $widgets,
	));
})->bind('index');

$app->get('/404', function() use ($app) {
	return $app['twig']->render('404.twig', array(
		'focus' => 'index',
	));
})->bind('404');

$app->get('/admin/', function() use ($app) {
	return $app->redirect($app['url_generator']->generate('admindashboard'));
})->bind('admin');

$app->get('/admin/dashboard/', function() use ($app, $config) {

	// reorganise configuration
	$providers = array();
	foreach ($config['providers'] as $name => $provider) {
		if ($provider['type'] == 'sickbeard' || $provider['type'] == 'couchpotato') {
			$providers[$provider['config']['host'].$provider['config']['port'].$provider['config']['basename'].$provider['config']['api_key']] = array(
				'host'		=> $provider['config']['host'],
				'port'		=> $provider['config']['port'],
				'basename'	=> $provider['config']['basename'],
				'api_key'	=> $provider['config']['api_key'],
				'titles'	=> array_merge((array)$provider['title'], (array)@$providers[$provider['config']['host'].$provider['config']['port'].$provider['config']['basename'].$provider['config']['api_key']]['titles']),
				'name'		=> $name,
				'type'		=> $provider['type'],
			);
		}
	}
	
	$returncode = '';
	if (isset($_GET['reload']) && !empty($_GET['reload'])) {
		if (array_key_exists($_GET['reload'], $config['providers'])) {
			switch($config['providers'][$_GET['reload']]['type']) {
				case 'couchpotato':
					$class = new Couchpotato\Couchpotato($app['db']);
					$returncode = $class->reload($_GET['reload']);
					break;
				case 'sickbeard':
					$class = new Sickbeard\Sickbeard($app['db']);
					$returncode = $class->reload($_GET['reload']);
					break;
			
			}
		}
	}
	
	
	return $app['twig']->render('admin.index.twig', array(
		'returncode'	=> $returncode,
		'focus'			=> 'admin',
		'adminfocus'	=> 'dashboard',
		'providers'		=> $providers,
	));
})->bind('admindashboard');

$app->get('/admin/users/', function() use ($app) {
	return $app['twig']->render('admin.index.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'users',
	));
})->bind('adminusers');

$app->get('/admin/newsletter/', function() use ($app) {
	return $app['twig']->render('admin.index.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'newsletter',
	));
})->bind('adminnewsletter');

$app->get('/admin/configurator/', function() use ($app) {
	return $app['twig']->render('admin.index.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'configurator',
	));
})->bind('adminconfigurator');

$app->get('/admin/configuration/', function() use ($app) {
	return $app['twig']->render('admin.index.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'configuration',
	));
})->bind('adminconfiguration');

$app->get('/login', function(Symfony\Component\HttpFoundation\Request $request) use ($app) {
	return $app['twig']->render('login.twig', array(
		'error' => $app['security.last_error']($request),
		'focus' => 'admin',
		'last_username' => $app['session']->get('_security.last_username'),
	));
})->bind('login');

$app->get('/list/{provider}/', function($provider) use ($app) {
	global $config;
	return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
});

$app->get('/list/{provider}', function($provider) use ($app) {
	global $config;
	return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
})->bind('listprovider');

$app->get('/list/{provider}/{func}', function($provider, $func) use ($app) {
	global $config;
	
	// test if URI is defined in configuration file
	if (isset($config['providers'][$provider])) {
		// test if provider exist
		switch ($config['providers'][$provider]['type']) {
			case 'couchpotato':
				$class = new Couchpotato\Couchpotato($app['db']);
				if (method_exists($class, $func)) {
					return $class->$func($provider);
				} else {
					return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
				}
				break;
			case 'sickbeard':
				$class = new Sickbeard\Sickbeard($app['db']);
				if (method_exists($class, $func)) {
					return $class->$func($provider);
				} else {
					return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
				}
				break;
			default:
				return $app->redirect($app['url_generator']->generate('list', array('provider' => $provider, 'func' => 'index')));
		}
	} else {
		return $app->redirect($app['url_generator']->generate('index'));
		
	}
})->bind('list');

$app->run();