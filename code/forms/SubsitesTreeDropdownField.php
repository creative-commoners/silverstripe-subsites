<?php

namespace SilverStripe\Subsites\Forms;


use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\View\Requirements;


/**
 * Wraps around a TreedropdownField to add ability for temporary
 * switching of subsite sessions.
 *
 * @package subsites
 */
class SubsitesTreeDropdownField extends TreeDropdownField
{

    private static $allowed_actions = [
        'tree'
    ];

    protected $subsiteID = 0;

    protected $extraClasses = [SubsitesTreeDropdownField::class];

    function Field($properties = [])
    {
        $html = parent::Field($properties);

        Requirements::javascript('subsites/javascript/SubsitesTreeDropdownField.js');

        return $html;
    }

    function setSubsiteID($id)
    {
        $this->subsiteID = $id;
    }

    function getSubsiteID()
    {
        return $this->subsiteID;
    }

    function tree(HTTPRequest $request)
    {
        $oldSubsiteID = Session::get('SubsiteID');
        Session::set('SubsiteID', $this->subsiteID);

        $results = parent::tree($request);

        Session::set('SubsiteID', $oldSubsiteID);

        return $results;
    }
}
