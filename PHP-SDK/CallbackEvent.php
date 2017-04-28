<?php

namespace Simbigo\Verstka;

/**
 * Class CallbackEvent
 */
class CallbackEvent
{
    /**
     * @var
     */
    private $articleId;
    /**
     * @var
     */
    private $content;
    /**
     * @var array
     */
    private $customFields;
    /**
     * @var
     */
    private $images;
    /**
     * @var
     */
    private $userId;

    /**
     * CallbackEvent constructor.
     *
     * @param $content
     * @param $articleId
     * @param $userId
     * @param $images
     * @param array $customFields
     */
    public function __construct($content, $articleId, $userId, $images, array $customFields)
    {
        $this->content = $content;
        $this->articleId = $articleId;
        $this->userId = $userId;
        $this->images = $images;
        $this->customFields = $customFields;
    }

    /**
     * @return mixed
     */
    public function getArticleId()
    {
        return $this->articleId;
    }

    /**
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @return array
     */
    public function getCustomFields()
    {
        return $this->customFields;
    }

    /**
     * @return mixed
     */
    public function getImages()
    {
        return $this->images;
    }

    /**
     * @return mixed
     */
    public function getUserId()
    {
        return $this->userId;
    }
}