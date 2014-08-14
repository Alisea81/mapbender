<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Mapbender\ManagerBundle\Component;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\PersistentCollection;
use Mapbender\CoreBundle\Component\Application as ApplicationComponent;
use Mapbender\CoreBundle\Component\Element as ElementComponent;
use Mapbender\CoreBundle\Component\SourceInstanceItem;
use Mapbender\CoreBundle\Component\SourceItem;
use Mapbender\CoreBundle\Entity\Application;
use Mapbender\CoreBundle\Entity\Element;
use Mapbender\CoreBundle\Entity\Contact;
use Mapbender\CoreBundle\Entity\Layerset;
use Mapbender\CoreBundle\Entity\Source;
use Mapbender\CoreBundle\Entity\SourceInstance;
use Mapbender\CoreBundle\Utils\ArrayUtil;
use Mapbender\CoreBundle\Utils\ClassPropertiesParser;
use Mapbender\CoreBundle\Utils\EntityUtil;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Description of ExchangeDenormalizer
 *
 * @author paul
 */
class ExchangeDenormalizer extends ExchangeSerializer implements DenormalizerInterface
{

    protected $mapper;

    /**
     * 
     * @param ContainerInterface $container container
     * @param array $mapper mapper old id <-> new id (object)
     */
    public function __construct(ContainerInterface $container, array $mapper)
    {
        parent::__construct($container);
        $this->em = $this->container->get('doctrine')->getManager();
        $this->mapper = $mapper;
    }

    private function addClassToMapper($class, $idName)
    {
        if (!isset($this->mapper[$class])) {
            $this->mapper[$class] = array(
                self::KEY_PRIMARY => $idName,
                self::KEY_MAP => array()
            );
        }
    }

    private function addToMapper($class, $oldId, $newId)
    {
        $this->mapper[$class][self::KEY_MAP][$oldId] = $newId;
    }

    private function getPrimary($class)
    {
        return $this->mapper[$class][self::KEY_PRIMARY];
    }

    private function getOldId($class, $newId)
    {
        foreach ($this->mapper[$class][self::KEY_MAP] as $oldId_ => $newId_) {
            if ($newId === $newId_) {
                return $oldId_;
            }
        }
        return null;
    }

    private function getNewId($class, $oldId)
    {
        if (isset($this->mapper[$class][self::KEY_MAP][$oldId])) {
            return $this->mapper[$class][self::KEY_MAP][$oldId];
        } else {
            return null;
        }
    }

    private function findExistingEntity($class, $oldId)
    {
        $newId = $this->getNewId($class, $oldId);
        if ($newId !== null) {
            $prim = $this->getPrimary($class);
            return EntityUtil::findOneBy($this->em, $class, $prim, $newId);
        } else {
            return null;
        }
    }

    private function findIdName($fields)
    {
        foreach ($fields as $fieldName => $filedProps) {
            if (isset($filedProps['Id'])) {
                return $fieldName;
            }
        }
        return null;
    }

    public function mapSource($data, $class, $objectExists)
    {
        $reflectionClass = new \ReflectionClass($class);
        $constructorArguments = $this->getClassConstructParams($data) ? : array();
        $object = $reflectionClass->newInstanceArgs($constructorArguments);
        $fields = ClassPropertiesParser::parseFields(get_class($object));
        $idName = $this->findIdName($fields);
        if ($idName) {
            $this->addClassToMapper($class, $idName);
        }
        foreach ($fields as $fieldName => $fieldProps) {
            if (!isset($fieldProps[self::KEY_GETTER])) {
                continue;
            }
            $reflectionMethod = new \ReflectionMethod(get_class($objectExists), $fieldProps[self::KEY_GETTER]);
            $fieldValue = $reflectionMethod->invoke($objectExists);
            if ($fieldName === $idName) {
                if (get_class($objectExists) === $class && isset($fieldProps[self::KEY_GETTER])) {
                    $this->addToMapper($class, $data[$idName], $fieldValue);
                }
            } elseif (is_object($fieldValue)) {
                if ($fieldValue instanceof PersistentCollection &&
                    isset($data[$fieldName]) && is_array($data[$fieldName]) && $objectExists instanceof Source) {
                    $collection = $fieldValue->toArray();
                    for ($i = 0; $i < count($data[$fieldName]); $i++) {
                        $this->mapSource($data[$fieldName][$i], $this->getClassName($data[$fieldName][$i]),
                            $collection[$i]);
                    }
                } elseif ($fieldValue instanceof Contact) {
                    $this->mapSource($data[$fieldName], $this->getClassName($data[$fieldName]), $fieldValue);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, $class, $format = null, array $context = array())
    {
        $fields = ClassPropertiesParser::parseFields($class);
        $fixedField = array();
        $object = $this->findOrCreateEntity($class, $data, $fields, $fixedField);
        if ($object !== null) {
            foreach ($fields as $fieldName => $fieldProps) {
                if (!isset($fieldProps[self::KEY_SETTER]) || !isset($fieldProps[self::KEY_GETTER]) ||
                    in_array($fieldName, $fixedField) || !key_exists($fieldName, $data)) {
                    continue;
                }
                $fieldValue = $data[$fieldName];
                if ($fieldValue === null) {
                    $reflectionMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_SETTER]);
                    $reflectionMethod->invoke($object, null);
                } else if (is_integer($fieldValue) || is_float($fieldValue) || is_string($fieldValue) || is_bool($fieldValue)) {
                    $reflectionMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_SETTER]);
                    $reflectionMethod->invoke($object, $fieldValue);
                } else if (is_array($fieldValue)) {
                    if (ArrayUtil::isAssoc($fieldValue)) {
                        $subObjectClassName = $this->getClassName($fieldValue);
                        if ($subObjectClassName) {
                            $subObject = $this->denormalize($fieldValue, $subObjectClassName);
                            if ($object instanceof SourceInstance) {
                                $this->handleSourceInstance($object, $subObject, $fieldName, $fieldValue, $fieldProps);
                            } elseif ($object instanceof SourceInstanceItem) {
                                $this->handleSourceInstanceItem($object, $subObject, $fieldName, $fieldValue,
                                    $fieldProps);
                            } else {
                                $this->handleCommon($object, $subObject, $fieldName, $fieldValue, $fieldProps);
                            }
                            unset($subObject);
                            unset($subObjectClassName);
                        } elseif ($object instanceof Element) {
                            $this->handleElement($object, $fieldName, $fieldValue, $fieldProps);
                        } else {
                            $a = 0;
                        }
                    } else {
                        $getMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_GETTER]);
                        $getMethodResult = $getMethod->invoke($object);
                        if ($getMethodResult !== null && $getMethodResult instanceof PersistentCollection) {
                            $this->handleArrayCollection($object, $fieldName, $fieldValue, $fieldProps);
                        } elseif ($getMethodResult !== null && is_array($getMethodResult)) {
                            $this->handleArray($object, $fieldName, $fieldValue, $fieldProps);
                        } else
                        if ($object instanceof Element) {
                            $this->handleElement($object, $fieldName, $fieldValue, $fieldProps);
                        } else {
                            $a = 0; # $fieldName configuration $object Element (all)
                        }
                        if ($getMethodResult) {
                            unset($getMethodResult);
                            unset($getMethod);
                        }
                    }
                } else {
                    $a = 0;
                }
            }
        }
        return $object;
    }

    private function findOrCreateEntity($class, $data, $fields, &$fixedField)
    {
        $reflectionClass = new \ReflectionClass($class);
        $constructorArguments = $this->getClassConstructParams($data) ? : array();
        $idName = $this->findIdName($fields);
        $fixedField[] = $idName;
        $object = $this->findExistingEntity($class, $data[$idName]);
        if ($object === null) { # not found -> create
            $object = $reflectionClass->newInstanceArgs($constructorArguments);
            foreach ($fields as $fieldName => $fieldProps) { #set not null values
                if (!isset($fieldProps['Column']) || !key_exists($fieldName, $data)) {
                    continue;
                }
                $column = $fieldProps['Column'];
                if (isset($column[self::KEY_UNIQUE]) && $column[self::KEY_UNIQUE] === 'true') {
                    $val = EntityUtil::getUniqueValue($this->em, $class, $fieldName, $data[$fieldName], '');
                    $reflectionMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_SETTER]);
                    $reflectionMethod->invoke($object, $val);
                    $fixedField[] = $fieldName;
                } elseif ($fieldName !== $idName && isset($fieldProps[self::KEY_SETTER])) {
                    $exists = key_exists('nullable', $column);
                    if (!$exists || ($exists && $column['nullable'] === 'false')) {
                        if ($object instanceof Application) {
                            $val = $data[$fieldName];
                            if ($fieldName === 'template') {
                                $tmplClass = new \ReflectionClass($data[$fieldName]);
                            } elseif ($fieldName === 'updated') {
                                $val = new \DateTime();
                            }
                            $reflectionMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_SETTER]);
                            $reflectionMethod->invoke($object, $val);
                            $fixedField[] = $fieldName;
                        } elseif ($data[$fieldName] !== null) {
                            $reflectionMethod = new \ReflectionMethod($class, $fieldProps[self::KEY_SETTER]);
                            $reflectionMethod->invoke($object, $data[$fieldName]);
                            $fixedField[] = $fieldName;
                        } else {
                            throw new \Exception("not null field");
                        }
                    }
                }
            }
            $this->addClassToMapper($class, $idName);
            $this->em->persist($object);
            $this->em->flush();
            $reflectionMethod = new \ReflectionMethod($class, $fields[$idName][self::KEY_GETTER]);
            $idValue = $reflectionMethod->invoke($object);
            $this->addToMapper($class, $data[$idName], $idValue);
        }
        return $object;
    }

    private function handleElement($object, $fieldName, $fieldValue, $fieldProps)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $fieldProps[self::KEY_SETTER]);
        $reflectionMethod->invoke($object, $fieldValue);
        $this->em->persist($object);
        $this->em->flush();
    }

    private function handleCommon($object, $newObject, $fieldName, $fieldValue, $fieldProps)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $fieldProps[self::KEY_SETTER]);
        $this->em->persist($newObject);
        $this->em->flush();
        $reflectionMethod->invoke($object, $newObject);
        $this->em->persist($object);
        $this->em->flush();
    }

    private function handleSourceInstance($sourceInstance, $newObject, $fieldName, $fieldValue, $fieldProps)
    {
        if ($newObject instanceof Source) {
            $reflectionMethod = new \ReflectionMethod(get_class($sourceInstance), $fieldProps[self::KEY_SETTER]);
            $reflectionMethod->invoke($sourceInstance, $newObject);
            $this->em->persist($sourceInstance);
            $this->em->flush();
        } elseif ($newObject instanceof Layerset) {
            $reflectionMethod = new \ReflectionMethod(get_class($sourceInstance), $fieldProps[self::KEY_SETTER]);
            $reflectionMethod->invoke($sourceInstance, $newObject);
            $this->em->persist($sourceInstance);
            $this->em->flush();
        } else {
            $a = 0;
        }
    }

    private function handleSourceInstanceItem($object, $newObject, $fieldName, $fieldValue, $fieldProps)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $fieldProps[self::KEY_SETTER]);
        $reflectionMethod->invoke($object, $newObject);
        $this->em->persist($object);
        $this->em->flush();
    }

    private function handleArrayCollection($object, $fieldName, $fieldValue, $fieldProps)
    {
        $collection = new ArrayCollection();
        foreach ($fieldValue as $item) {
            $newclassName = $this->getClassName($item);
            if ($newclassName) {
                $newObject = $this->denormalize($item, $newclassName);
                $this->em->persist($newObject);
                if ($object instanceof Layerset && $newObject instanceof SourceInstance) {
                    $newObject->generateConfiguration();
                    $this->em->persist($newObject);
                }
                $this->em->flush();
                $collection->add($newObject);
            } else {
                $a = 0;
            }
        }
        $reflectionMethod = new \ReflectionMethod(get_class($object), $fieldProps[self::KEY_SETTER]);
        $reflectionMethod->invoke($object, $collection);
        $this->em->persist($object);
        $this->em->flush();
    }

    private function handleArray($object, $fieldName, $fieldValue, $fieldProps)
    {
        $reflectionMethod = new \ReflectionMethod(get_class($object), $fieldProps[self::KEY_SETTER]);
        $reflectionMethod->invoke($object, $fieldValue);
    }

    private function handleConfiguration($value)
    {
        if (is_array($value)) {
            if (ArrayUtil::isAssoc($value)) {
                $className = $this->getClassName($value);
                if ($className) {
                    $fields = ClassPropertiesParser::parseFields($className);
                    $idName = $this->findIdName($fields);
                    $entity = $this->findExistingEntity($className, $value[$idName]);
                    $reflectionMethod = new \ReflectionMethod($className, $fields[$idName][self::KEY_GETTER]);
                    $val = $reflectionMethod->invoke($entity);
                    return $reflectionMethod->invoke($entity);
                } else {
                    foreach ($value as $key => $subvalue) {
                        $value[$key] = $this->handleConfiguration($subvalue);
                    }
                    return $value;
                }
            } else {
                $help = array();
                foreach ($value as $subvalue) {
                    $help[] = $this->handleConfiguration($subvalue);
                }
                return $help;
            }
        } else {
            return $value;
        }
    }

    public function generateElementConfiguration(Application $app)
    {
        foreach ($app->getElements() as $element) {
            $configuration = $element->getConfiguration();
            foreach ($configuration as $key => $value) {
                $configuration[$key] = $this->handleConfiguration($value);
            }
            $element->setConfiguration($configuration);
            $this->em->persist($element);
            $this->em->flush();
        }
        $this->em->persist($app);
        $this->em->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return true;
    }

}
