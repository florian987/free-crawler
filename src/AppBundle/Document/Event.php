<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
* @MongoDB\Document(db="my_db", collection="events")
*/
class Event
{
    /**
    * @MongoDB\Id
    */
    protected $id;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $eventId;

    /**
    * @MongoDB\Field(type="string")
    */
    protected $status;

    /**
    * @MongoDB\Field(type="collection")
    */
    protected $info;

    /**
     * @return string
     */
    public function __toString()
    {
        $infos = implode(' - ', $this->getInfo());

        return $infos.' ('.$this->getStatus().')';
    }

    /**
     * Get id
     *
     * @return id $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set status
     *
     * @param string $status
     * @return self
     */
    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Get status
     *
     * @return string $status
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set info
     *
     * @param collection $info
     * @return self
     */
    public function setInfo($info)
    {
        $this->info = $info;
        return $this;
    }

    /**
     * Get info
     *
     * @return collection $info
     */
    public function getInfo()
    {
        return $this->info;
    }

    /**
     * Set eventId
     *
     * @param string $eventId
     * @return self
     */
    public function setEventId($eventId)
    {
        $this->eventId = $eventId;
        return $this;
    }

    /**
     * Get eventId
     *
     * @return string $eventId
     */
    public function getEventId()
    {
        return $this->eventId;
    }
}
