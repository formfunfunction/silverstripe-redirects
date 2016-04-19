<?php

use Heyday\Redirects\DataSource\CachedDataSource;

/**
 * @package Heyday\Redirects
 */
class RedirectUrl extends DataObject implements PermissionProvider
{
    /**
     * Permission for managing redirects
     */
    const PERMISSION = 'MANAGE_REDIRECTS';

    /**
     * @var array
     */
    private static $db = [
        'From' => 'Varchar(2560)',
        'To' => 'Varchar(2560)',
        'Type' => 'Enum("Permanent,Vanity","Permanent")'
    ];

    /**
     * @var array
     */
    private static $has_one = [
        'FromRelation' => 'SiteTree',
        'ToRelation' => 'SiteTree'
    ];

    /**
     * @var array
     */
    private static $summary_fields = [
        'FromLink',
        'ToLink',
        'Type'
    ];

    /**
     * @var array
     */
    private static $searchable_fields = [
        'From',
        'To',
        'Type'
    ];

    /**
     * @var \Heyday\Redirects\DataSource\CachedDataSource
     */
    protected $dataSource;

    /**
     * @param \Heyday\Redirects\DataSource\CachedDataSource $dataSource
     */
    public function setDataSource(CachedDataSource $dataSource)
    {
        $this->dataSource = $dataSource;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = new FieldList();

        $from = new TextField('From', 'From');
        $from->setRightTitle('(e.g "/my-page/")- always include the /');
        $to = new TextField('To', 'To');
        $to->setRightTitle('e.g "/my-page/" for internal pages or "http://google.com/" for external websites (and include the scheme - http:// or https://)');

        $fields->push($manual = new ToggleCompositeField(
            'TextLinks',
            'Enter urls',
            [
                $from,
                $to

            ]
        ));

        $fields->push($page = new ToggleCompositeField(
            'SiteTree',
            'Select pages from list',
            [
                new TreeDropdownField('FromRelationID', 'From', 'SiteTree'),
                new TreeDropdownField('ToRelationID', 'To', 'SiteTree')
            ]
        ));

        $fields->push(new DropdownField(
            'Type',
            'Type',
            [
                'Vanity' => 'Vanity',
                'Permanent' => 'Permanent'

            ]
        ));

        if ($this->getField('From') || $this->getField('To')) {
            $manual->setStartClosed(false);
        }

        if ($this->getField('FromRelationID') || $this->getField('ToRelationID')) {
            $page->setStartClosed(false);
        }

        return $fields;
    }

    /**
     * @return string|bool
     */
    public function getFromLink()
    {
        return $this->getLink('From');
    }

    /**
     * @return string|bool
     */
    public function getToLink()
    {
        return $this->getLink('To');
    }

    /**
     * Returns the right status code depending on type of redirect.
     * @return int
     */
    public function getStatusCode()
    {
        switch (strtolower($this->Type)) {
            case 'permanent':
                return 301;
            case 'vanity':
                return 302;
            default:
                return 301;
        }
    }

    /**
     * @param string $type
     * @return string|bool
     */
    protected function getLink($type)
    {
        if (!$relation = $this->getLinkRelation($type)) {
            return $this->getField($type);
        }

        return sprintf(
            "/%s",
            ltrim($relation->RelativeLink(), '/')
        );
    }

    /**
     * @param string $type
     * @return bool|SiteTree
     */
    protected function getLinkRelation($type)
    {
        $relation = $this->getComponent(sprintf("%sRelation", $type));

        return $relation->exists() ? $relation : false;
    }

    /**
     * @return RedirectUrlValidator
     */
    public function getCMSValidator()
    {
        return new RedirectUrlValidator();
    }

    /**
     * @return array
     */
    public function providePermissions()
    {
        return [
            self::PERMISSION => "Manage redirections"
        ];
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canEdit($member = null)
    {
        return $this->hasPermission($member);
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canCreate($member = null)
    {
        return $this->hasPermission($member);
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canDelete($member = null)
    {
        return $this->hasPermission($member);
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canView($member = null)
    {
        return $this->hasPermission($member);
    }

    /**
     * @param null $member
     * @return bool|int
     */
    protected function hasPermission($member = null)
    {
        return Permission::checkMember($member, self::PERMISSION);
    }

    /**
     * Clear out from and to manual links if we have a relation
     */
    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if ($this->isChanged('FromRelationID') && $this->getLinkRelation('From')) {
            $this->setField('From', '');
        }

        if ($this->isChanged('ToRelationID') && $this->getLinkRelation('To')) {
            $this->setField('To', '');
        }

        if ($this->isChanged('From') && $this->getField('From')) {
            $this->setField('FromRelationID', 0);
        }

        if ($this->isChanged('To') && $this->getField('To')) {
            $this->setField('ToRelationID', 0);

        }
    }


    /**
     * run a cleanup on the uris and
     * check for duplicates
     * @return ValidationResult
     */
    protected function validate()
    {
        $result = parent::validate();
        $this->From = $this->cleanURI($this->From);
        $this->To = $this->cleanURI($this->To);
        $this->checkForDuplicates($result);
        $this->checkValidDestination($result);

        return $result;
    }


    /**
     * make a call to destination and make sure it does not return a 404
     * @param $result
     */
    protected function checkValidDestination($result)
    {

    }

    /**
     * check for duplicates
     * @param $result
     */
    protected function checkForDuplicates($result)
    {
        if (empty($this->FromRelationID) || $this->FromRelationID == 0) {
            $existing = RedirectUrl::get()->where("`From` = '$this->From'")->first();
        } else {
            $existing = RedirectUrl::get()->where("`FromRelationID` = '$this->FromRelationID'")->first();
        }

        if ($existing instanceof RedirectUrl && $existing->ID != $this->ID) {
            $result->combineAnd(
                new ValidationResult(
                    false,
                    "A redirect for this URL already exists.
Please edit <a href='/admin/redirects-management/RedirectUrl/EditForm/field/RedirectUrl/item/$existing->ID/edit'>the existing one</a> instead."));
        }
    }

    /**
     * Clean up the URIs. if not external, make sure they start and finish with "/"
     * and do not contain whitespaces
     */
    protected function cleanURI($field)
    {
        if (isset($field) && substr($field, 0, 4) != "http") {
            $field = str_replace(' ', '', $field);
            if (substr($field, 0, 1) != '/') {
                $field = '/' . $field;
            }
            if (substr($field, -1) != '/') {
                $field = $field . '/';
            }
        }

        return $field;
    }

    /**
     *
     */
    protected function onAfterWrite()
    {
        parent::onAfterWrite();
        if (isset($this->dataSource)) {
            $this->dataSource->delete();
        }
    }

    /**
     *
     */
    protected function onAfterDelete()
    {
        parent::onAfterDelete();
        if (isset($this->dataSource)) {
            $this->dataSource->delete();
        }
    }
}
