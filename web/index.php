<?php

require_once __DIR__.'/../vendor/autoload.php';

use App\Providers\Sickbeard;
use App\Providers\Couchpotato;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

# Add form support
$app->register(new Silex\Provider\FormServiceProvider());

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
$userlangage = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
	'locale'			=> substr($userlangage[0], 0, 2),
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
$app->error(function (\Exception $e, $code) {
	global $app;
    switch ($code) {
        case 404:
			return $app->redirect($app['url_generator']->generate('404'));
            break;
        default:
            $message = 'We are sorry, but something went terribly wrong.';
    }

    return new Symfony\Component\HttpFoundation\Response($message);
});


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
	if (!$app['security']->isGranted('ROLE_ADMIN')) {
		return $app->redirect($app['url_generator']->generate('login'));
    }
    $error = array();

	try {
		$listusers = $app['db']->fetchAll("SELECT id, username, roles FROM users ORDER BY username, roles");
	} catch(Exception $e) {
		$error[] = $e->getMessage();
	}

    return $app['twig']->render('admin.users.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'users',
		'data'			=> $listusers,
		'error'			=> $error
	));
})->bind('adminusers');

$app->get('/admin/users/delete/{id}', function($id) use ($app) {
	if (!$app['security']->isGranted('ROLE_ADMIN')) {
		return $app->redirect($app['url_generator']->generate('login'));
    }
    $error = array();

	try {
		$app['db']->delete('users', array(
			'id'	=> $id,
		));
		return $app->redirect($app['url_generator']->generate('adminusers'));
	} catch(Exception $e) {
		$error[] = $e->getMessage();
	}

    return $error;
})->bind('admindeleteusers');

$app->match('/admin/users/edit/{id}', function(Request $request, $id) use ($app) {
	if (!$app['security']->isGranted('ROLE_ADMIN')) {
		return $app->redirect($app['url_generator']->generate('login'));
    }
    $error = array();
	$retour = '';

	try {
		$user = $app['db']->fetchAssoc("SELECT id, username, roles FROM users WHERE id=:id", array(
			'id'	=> $id,
		));
		
		$form = $app['form.factory']->createBuilder('form')
			->add('name', 'text', array(
				'label'		=> 'Name',
				'data'		=> $user['username'],
				'required'	=> true,
				'attr'		=> array('placeholder' => 'Name'),
			))
			->add('password', 'password', array(
				'label'		=> 'Password',
				'required'	=> false,
				'attr'		=> array('placeholder' => 'Password'),
			))
			->add('admin','checkbox',array(
				'label'		=> 'Admin',
				'required'	=> false,
				'attr'		=> ($user['roles'] == 'ROLE_ADMIN')?array('checked' => 'checked'):array(),
			))
			->getForm();

		if ('POST' == $request->getMethod()) {
	
			$form->bind($request);

			if ($form->isValid()) {
				$data = $form->getData();
				$password=$app['security.encoder.digest']->encodePassword($data['password'],'');
				
				
				$role = 'ROLE_USER';
				if($data['admin']==true){
					$role = 'ROLE_ADMIN';
				}
				
				try{
					if (empty($data['password'])) {
						$sql = "UPDATE users SET username=:username, roles=:role WHERE id=:id";
						$app['db']->executeUpdate($sql, array(
							'username'	=> $data['name'],
							'role'		=> $role,
							'id'		=> (int) $id,
						));
					} else {
						$sql = "UPDATE users SET username=:username, roles=:role, password=:password WHERE id=:id";
						$app['db']->executeUpdate($sql, array(
							'username'	=> $data['name'],
							'role'		=> $role,
							'password'	=> $password,
							'id'		=> (int) $id,
						));
					}
					$retour = 'User have been updated';
					
					// rechargement des données
					$user = $app['db']->fetchAssoc("SELECT id, username, roles FROM users WHERE id=:id", array(
						'id'	=> $id,
					));
				} catch(Exception $e){
					$error[] = "Can't update user";
				}

			}
		}	
	} catch(Exception $e) {
		$error[] = $e->getMessage();
	}

    return $app['twig']->render('admin.users.form.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'users',
		'form'			=> $form->createView(),
		'error'			=> $error,
		'retour'		=> $retour,
	));
	
})->bind('admineditusers');

$app->match('/admin/users/add', function(Request $request) use ($app) {
	if (!$app['security']->isGranted('ROLE_ADMIN')) {
		return $app->redirect($app['url_generator']->generate('login'));
    }
    $error = array();

    $form = $app['form.factory']->createBuilder('form')
        ->add('name', 'text', array(
			'label'	=> 'Name',
			'required'	=> true,
			'attr'	=> array('placeholder' => 'Name'),
		))
        ->add('password', 'password', array(
			'label'	=> 'Password',
			'required'	=> true,
			'attr' => array('placeholder' => 'Password'),
		))
        ->add('admin','checkbox',array(
			'label'		=> 'Admin',
			'required'	=> false,
			'attr'		=> array('checked' => 'checked'),
		))
        ->getForm();

    if ('POST' == $request->getMethod()) {
		
        $form->bind($request);

        if ($form->isValid()) {
			$data = $form->getData();
			$password=$app['security.encoder.digest']->encodePassword($data['password'],'');
			
			
			$role = 'ROLE_USER';
            if($data['admin']==true){
				$role = 'ROLE_ADMIN';
            }
			
			try{
				$app['db']->insert('users', array(
					'username' => $data['name'],
					'password' => $password,
					'roles' => $role
				)); 
				return $app->redirect($app['url_generator']->generate('adminusers'));
			} catch(Exception $e){
				$error[] = "Existing user";
			}

        }
    }

    return $app['twig']->render('admin.users.form.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'users',
		'form'			=> $form->createView(),
		'error'			=> $error
	));

})->bind('adminaddusers');


$app->match('/admin/configuration/', function(Request $request) use ($app, $dbconf) {
	if (!$app['security']->isGranted('ROLE_ADMIN')) {
		return $app->redirect($app['url_generator']->generate('login'));
    }
    $error = array();
	$retour = '';

    $form = $app['form.factory']->createBuilder('form')
        ->add('notice', 'text', array(
			'label'	=> 'Notice',
			'data'	=> @$dbconf['notice'],
			'required'	=> true,
			'attr'	=> array('placeholder' => 'Notice'),
		))
        ->getForm();

    if ('POST' == $request->getMethod()) {
		
        $form->bind($request);

        if ($form->isValid()) {
			$data = $form->getData();
			
			try{
				$stmt = $app['db']->prepare("TRUNCATE TABLE `config`");
				$stmt->execute();
				$app['db']->insert('config', array(
					'name' => 'notice',
					'value' => $data['notice'],
				)); 
				$retour = 'Configuration updated';
			} catch(Exception $e){
				$error[] = $e->getMessage();
			}

        }
    }

    return $app['twig']->render('admin.configuration.twig', array(
		'focus'			=> 'admin',
		'adminfocus'	=> 'configuration',
		'form'			=> $form->createView(),
		'error'			=> $error,
		'retour'		=> $retour,
	));
})->bind('adminconfiguration');

$app->get('/login', function(Request $request) use ($app) {
	return $app['twig']->render('login.twig', array(
		'error' => $app['security.last_error']($request),
		'focus' => 'admin',
		'last_username' => $app['session']->get('_security.last_username'),
	));
})->bind('login');

$app->get('/admin/logout', function(Request $request) use ($app) {
	$app['session']->set('isAuthenticated', false);
    return $app['login.basic_login_response'];
})->bind('logout');

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