<?php

namespace Nettrine\ORM\DI;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\ORM\Configuration;
use Nette\DI\CompilerExtension;
use Nette\InvalidStateException;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpLiteral;
use Nette\Utils\Validators;
use Nettrine\ORM\Mapping\AnnotationDriver;

class OrmAnnotationsExtension extends CompilerExtension
{

	/** @var mixed[] */
	public $defaults = [
		'paths' => ['%appDir%'],
		'ignore' => [],
		'cache' => FilesystemCache::class,
		'cacheDir' => '%tempDir%/cache/Doctrine.Annotations',
	];

	/**
	 * Register services
	 *
	 * @return void
	 */
	public function loadConfiguration()
	{
		if (!$this->compiler->getExtensions(OrmExtension::class)) {
			throw new InvalidStateException(
				sprintf('You should register %s before %s.', OrmExtension::class, get_class($this))
			);
		}

		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		$reader = $builder->addDefinition($this->prefix('annotationReader'))
			->setClass(AnnotationReader::class)
			->setAutowired(FALSE);

		Validators::assertField($config, 'ignore', 'array');
		foreach ($config['ignore'] as $annotationName) {
			$reader->addSetup('addGlobalIgnoredName', [$annotationName]);
			AnnotationReader::addGlobalIgnoredName($annotationName);
		}

		// Cache
		$builder->addDefinition($this->prefix('annotationsCache'))
			->setFactory($config['cache'], [$builder->expand($config['cacheDir'])]);

		//TODO otestovat predani @...

		$builder->addDefinition($this->prefix('reader'))
			->setClass(Reader::class)
			->setFactory(CachedReader::class, [
				$this->prefix('@annotationReader'),
				$this->prefix('@annotationsCache'),
			]);

		$builder->addDefinition($this->prefix('annotationDriver'))
			->setClass(AnnotationDriver::class, [$this->prefix('@reader') , [$builder->expand($config['paths'])]]);

		$builder->getDefinitionByType(Configuration::class)
			->addSetup('setMetadataDriverImpl', [$this->prefix('@annotationDriver')]);

		AnnotationRegistry::registerLoader('class_exists');
	}

	/**
	 * @param ClassType $classType
	 * @return void
	 */
	public function afterCompile(ClassType $classType)
	{
		$initialize = $classType->getMethod('initialize');
		$original = (string) $initialize->getBody();
		$initialize->setBody('?::registerLoader("class_exists");' . "\n", [new PhpLiteral(AnnotationRegistry::class)]);
		$initialize->addBody($original);
	}

}
