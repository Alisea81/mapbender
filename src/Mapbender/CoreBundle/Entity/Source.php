<?php

namespace Mapbender\CoreBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;

/**
 * Source entity
 *
 * @author Paul Schmidt
 *
 * @ORM\Entity
 * @ORM\Table(name="mb_core_source")
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="discr", type="string")
 * ORM\DiscriminatorMap({"mb_core_source" = "Source"})
 */
abstract class Source
{

    /**
     * @var integer $id
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string $title The source title
     * @ORM\Column(type="string", nullable=true)
     */
    protected $title;

    /**
     * @var string $alias The source alias
     * @ORM\Column(type="string", length=128, nullable=true)
     */
    protected $alias = "";
    
    
    /**
     * @var boolean $valid is a source valid
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $valid = false;

    /**
     * @var string $description The source description
     * @ORM\Column(type="text", nullable=true)
     */
    protected $description;

    /**
     * @var array A list of Source keywords
     * @ORM\ManyToMany(targetEntity="Mapbender\CoreBundle\Entity\Keyword", cascade={"persist"})
     * @ORM\JoinTable(name="mb_core_sources_keywords",
     *      joinColumns={@ORM\JoinColumn(name="source_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="keyword_id", referencedColumnName="id")}
     *      )
     */
    protected $keywords;
    
    public function __construct()
    {
	$this->keywords = new ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set title
     *
     * @param string $title
     * @return Source
     */
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Get title
     *
     * @return string 
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return Source
     */
    public function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Get description
     *
     * @return string 
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set alias
     *
     * @param string $alias
     * @return Source
     */
    public function setAlias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Get alias
     *
     * @return string 
     */
    public function getAlias()
    {
        return $this->alias;
    }
    
    /**
     * Set keywords
     *
     * @param array $keywords
     * @return Source
     */
    public function setKeywords($keywords)
    {
        $this->keywords = $keywords;
        return $this;
    }

    /**
     * Get keywords
     *
     * @return string 
     */
    public function getKeywords()
    {
        return $this->keywords;
    }

    /**
     * Add keyword
     *
     * @param Keyword $keyword
     * @return Source
     */
    public function addKeyword(Keyword $keyword)
    {
        $this->keywords->add($keyword);
        return $this;
    }


    /**
     * Get full class name
     *
     * @return string
     */
    public function getClassname()
    {
        return get_class();
    }

    /**
     * Set valid
     *
     * @param boolean $valid
     * @return Source
     */
    public function setValid($valid)
    {
        $this->valid = $valid;
        return $this;
    }

    /**
     * Get valid
     *
     * @return boolean 
     */
    public function getValid()
    {
        return $this->valid;
    }

    /**
     * Returns a Source as String
     * 
     * @return String Source as String
     */
    public function __toString()
    {
        return (string) $this->id;
    }

    /**
     * Returns a source type
     *
     * @return String type
     */
    public abstract function getType();

    /**
     * Returns a manager type 
     *
     * @return String a manager type
     */
    public abstract function getManagertype();

    /**
     * Creates a SourceInstance
     * 
     * @return SourceInstance a new SourceInstance.
     */
    public abstract function createInstance();

    /**
     * Remove a source from a database.
     * 
     * @param EntityManager $em an EntityManager
     */
    public abstract function remove(EntityManager $em);
//    
//    /**
//     * Checks if a source is updateable.
//     * 
//     * @param Source $source an updated source.
//     * @return true if all source's instances can be updated otherwise false.
//     */
//    public abstract function isUpdateable(Source $updatedSource);
    
    /**
     * Update a source from a source
     * @param Source $updatedSource  an updated source.
     */
    public abstract function update(Source $updatedSource);
}
