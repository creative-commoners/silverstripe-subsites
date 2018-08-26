<?php

namespace SilverStripe\Subsites\Extensions;

use SilverStripe\Admin\AdminRootController;
use SilverStripe\Admin\CMSMenu;
use SilverStripe\Admin\LeftAndMainExtension;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\CMS\Controllers\CMSPagesController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Subsites\Controller\SubsiteXHRController;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\State\SubsiteState;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;

/**
 * Decorator designed to add subsites support to LeftAndMain
 *
 * @package subsites
 */
class LeftAndMainSubsites extends LeftAndMainExtension
{
    private static $allowed_actions = ['CopyToSubsite'];

    /**
     * Normally SubsiteID=0 on a DataObject means it is only accessible from the special "main site".
     * However in some situations SubsiteID=0 will be understood as a "globally accessible" object in which
     * case this property is set to true (i.e. in AssetAdmin).
     */
    private static $treats_subsite_0_as_global = false;

    public function init()
    {
        Requirements::css('silverstripe/subsites:css/LeftAndMain_Subsites.css');
        Requirements::javascript('silverstripe/subsites:javascript/LeftAndMain_Subsites.js');
        Requirements::javascript('silverstripe/subsites:javascript/VirtualPage_Subsites.js');
    }

    /**
     * Set the title of the CMS tree
     */
    public function getCMSTreeTitle()
    {
        $subsite = Subsite::currentSubsite();
        return $subsite ? Convert::raw2xml($subsite->Title) : _t(__CLASS__.'.SITECONTENTLEFT', 'Site Content');
    }

    public function updatePageOptions(&$fields)
    {
        $fields->push(HiddenField::create('SubsiteID', 'SubsiteID', SubsiteState::singleton()->getSubsiteId()));
    }

    /**
     * Find all subsites accessible for current user on this controller.
     *
     * @param bool $includeMainSite
     * @param string $mainSiteTitle
     * @param Member|int|null $member
     * @return ArrayList List of Subsite instances
     */
    public function sectionSites($includeMainSite = true, $mainSiteTitle = 'Main site', $member = null)
    {
        if ($mainSiteTitle === 'Main site') {
            $mainSiteTitle = _t('SilverStripe\\Subsites\\Model\\Subsite.MainSiteTitle', 'Main site');
        }

        // Rationalise member arguments
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        if (!$member) {
            return ArrayList::create();
        }
        if (!is_object($member)) {
            $member = DataObject::get_by_id(Member::class, $member);
        }

        $accessibleSubsites = ArrayList::create();
        $subsites = Subsite::all_sites($includeMainSite, $mainSiteTitle);

        foreach ($subsites as $subsite) {
            /** @var Subsite $subsite */
            $canAccess = SubsiteState::singleton()
                ->withState(function (SubsiteState $newState) use ($subsite, $member) {
                    $newState->setSubsiteId($subsite->ID);

                    return $this->canAccess($member);
                });

            if ($canAccess === false) {
                // Explicitly denied
                continue;
            }
            $accessibleSubsites->push($subsite);
        }

        return $accessibleSubsites;
    }

    /*
     * Returns a list of the subsites accessible to the current user.
     * It's enough for any section to be accessible for the section to be included.
     *
     * @return ArrayList
     */
    public function Subsites()
    {
        return Subsite::all_accessible_sites();
    }

    /*
     * Generates a list of subsites with the data needed to
     * produce a dropdown site switcher
     *
     * @return ArrayList
     */

    public function ListSubsites()
    {
        $list = $this->Subsites();
        $currentSubsiteID = SubsiteState::singleton()->getSubsiteId();

        if ($list == null || $list->count() == 1 && $list->first()->DefaultSite == true) {
            return false;
        }

        Requirements::javascript('silverstripe/subsites:javascript/LeftAndMain_Subsites.js');

        $output = ArrayList::create();

        foreach ($list as $subsite) {
            $currentState = $subsite->ID == $currentSubsiteID ? 'selected' : '';

            $output->push(ArrayData::create([
                'CurrentState' => $currentState,
                'ID' => $subsite->ID,
                'Title' => $subsite->Title,
            ]));
        }

        return $output;
    }

    public function alternateMenuDisplayCheck($controllerName)
    {
        if (!class_exists($controllerName)) {
            return false;
        }

        // Don't display SubsiteXHRController
        if (singleton($controllerName) instanceof SubsiteXHRController) {
            return false;
        }

        // Check subsite support.
        if (SubsiteState::singleton()->getSubsiteId() == 0) {
            // Main site always supports everything.
            return true;
        }

        // It's not necessary to check access permissions here. Framework calls canView on the controller,
        // which in turn uses the Permission API which is augmented by our GroupSubsites.
        $controller = singleton($controllerName);
        return $controller->hasMethod('subsiteCMSShowInMenu') && $controller->subsiteCMSShowInMenu();
    }

    public function CanAddSubsites()
    {
        return Permission::check('ADMIN', 'any', null, 'all');
    }

    /**
     * Helper for testing if the subsite should be adjusted.
     * @param string $adminClass
     * @param int $recordSubsiteID
     * @param int $currentSubsiteID
     * @return bool
     */
    public function shouldChangeSubsite($adminClass, $recordSubsiteID, $currentSubsiteID)
    {
        if (Config::inst()->get($adminClass, 'treats_subsite_0_as_global') && $recordSubsiteID == 0) {
            return false;
        }
        if ($recordSubsiteID != $currentSubsiteID) {
            return true;
        }
        return false;
    }

    /**
     * Check if the current controller is accessible for this user on this subsite.
     *
     * @param Member|int|null $member Will be added as a concrete param in 3.x
     * @return false|null False if a decision was explicitly made to deny access, otherwise null to delegate to core
     */
    public function canAccess()
    {
        // Allow us to accept a Member object passed in as an argument without breaking semver
        $passedMember = func_num_args() ? func_get_arg(0) : null;

        // Admin can access everything, no point in checking.
        $member = $passedMember ?: Security::getCurrentUser();
        if ($member
            && (Permission::checkMember($member, 'ADMIN') // 'Full administrative rights'
                || Permission::checkMember($member, 'CMS_ACCESS_LeftAndMain') // 'Access to all CMS sections'
            )
        ) {
            return true;
        }

        // Check we have a member
        if (!$member) {
            return null;
        }

        // Check that some subsites exist first
        if (!Subsite::get()->exists()) {
            return null;
        }

        // Get the current subsite ID
        $currentSubsiteId = SubsiteState::singleton()->getSubsiteId();

        // Check against the current user's associated groups
        $allowedInSubsite = false;
        foreach ($member->Groups() as $group) {
            // If any of the current user's groups have been given explicit access to the subsite, delegate to core
            if ($group->AccessAllSubsites) {
                $allowedInSubsite = true;
                break;
            }

            // Check if any of the current user's groups have been given explicit access to the current subsite
            $groupSubsiteIds = $group->Subsites()->column('ID');
            if (in_array($currentSubsiteId, $groupSubsiteIds)) {
                $allowedInSubsite = true;
                break;
            }
        }

        // If we know that the user is not allowed in this subsite, explicitly say this
        if (!$allowedInSubsite) {
            return false;
        }

        // Delegate to core
        return null;
    }

    /**
     * Prevent accessing disallowed resources. This happens after onBeforeInit has executed,
     * so all redirections should've already taken place.
     *
     * @return false|null
     */
    public function alternateAccessCheck()
    {
        if ($this->owner->canAccess() === false) {
            return false;
        }
    }

    /**
     * Redirect the user to something accessible if the current section/subsite is forbidden.
     *
     * This is done via onBeforeInit as it needs to be done before the LeftAndMain::init has a
     * chance to forbids access via alternateAccessCheck.
     *
     * If we need to change the subsite we force the redirection to /admin/ so the frontend is
     * fully re-synchronised with the internal session. This is better than risking some panels
     * showing data from another subsite.
     */
    public function onBeforeInit()
    {
        $request = Controller::curr()->getRequest();
        $session = $request->getSession();

        $state = SubsiteState::singleton();

        // FIRST, check if we need to change subsites due to the URL.

        // Catch forced subsite changes that need to cause CMS reloads.
        if ($request->getVar('SubsiteID') !== null) {
            // Clear current page when subsite changes (or is set for the first time)
            if ($state->getSubsiteIdWasChanged()) {
                // sessionNamespace() is protected - see for info
                $override = $this->owner->config()->get('session_namespace');
                $sessionNamespace = $override ? $override : get_class($this->owner);
                $session->clear($sessionNamespace . '.currentPage');
            }

            // Context: Subsite ID has already been set to the state via InitStateMiddleware

            // If the user cannot view the current page, redirect to the admin landing section
            if (!$this->owner->canView()) {
                return $this->owner->redirect(AdminRootController::config()->get('url_base') . '/');
            }

            $currentController = Controller::curr();
            if ($currentController instanceof CMSPageEditController) {
                /** @var SiteTree $page */
                $page = $currentController->currentPage();

                // If the page exists but doesn't belong to the requested subsite, redirect to admin/pages which
                // will show a list of the requested subsite's pages
                $currentSubsiteId = $request->getVar('SubsiteID');
                if ($page && (int) $page->SubsiteID !== (int) $currentSubsiteId) {
                    return $this->owner->redirect(CMSPagesController::singleton()->Link());
                }

                // Page does belong to the current subsite, so remove the query string parameter and refresh the page
                // Remove the subsiteID parameter and redirect back to the current URL again
                $request->offsetSet('SubsiteID', null);
                return $this->owner->redirect($request->getURL(true));
            }

            // Redirect back to the default admin URL
            return $this->owner->redirect($request->getURL());
        }

        // Automatically redirect the session to appropriate subsite when requesting a record.
        // This is needed to properly initialise the session in situations where someone opens the CMS via a link.
        $record = $this->owner->currentPage();
        if ($record
            && isset($record->SubsiteID, $this->owner->urlParams['ID'])
            && is_numeric($record->SubsiteID)
            && $this->shouldChangeSubsite(
                get_class($this->owner),
                $record->SubsiteID,
                SubsiteState::singleton()->getSubsiteId()
            )
        ) {
            // Update current subsite
            $canViewElsewhere = SubsiteState::singleton()->withState(function ($newState) use ($record) {
                $newState->setSubsiteId($record->SubsiteID);

                return (bool) $this->owner->canView(Security::getCurrentUser());
            });

            if ($canViewElsewhere) {
                // Redirect to clear the current page
                return $this->owner->redirect(
                    Controller::join_links($this->owner->Link('show'), $record->ID, '?SubsiteID=' . $record->SubsiteID)
                );
            }
            // Redirect to the default CMS section
            return $this->owner->redirect(AdminRootController::config()->get('url_base') . '/');
        }

        // SECOND, check if we need to change subsites due to lack of permissions.

        if ($this->owner->canAccess() === false) {
            $member = Security::getCurrentUser();

            // Current section is not accessible, try at least to stick to the same subsite.
            $menu = CMSMenu::get_menu_items();
            foreach ($menu as $candidate) {
                if ($candidate->controller && $candidate->controller != get_class($this->owner)) {
                    $accessibleSites = singleton($candidate->controller)->sectionSites(true, 'Main site', $member);
                    if ($accessibleSites->count()
                        && $accessibleSites->find('ID', SubsiteState::singleton()->getSubsiteId())
                    ) {
                        // Section is accessible, redirect there.
                        return $this->owner->redirect(singleton($candidate->controller)->Link());
                    }
                }
            }

            // If no section is available, look for other accessible subsites.
            foreach ($menu as $candidate) {
                if ($candidate->controller) {
                    $accessibleSites = singleton($candidate->controller)->sectionSites(true, 'Main site', $member);
                    if ($accessibleSites->count()) {
                        Subsite::changeSubsite($accessibleSites->First()->ID);
                        return $this->owner->redirect(singleton($candidate->controller)->Link());
                    }
                }
            }

            // We have not found any accessible section or subsite. User should be denied access.
            return Security::permissionFailure($this->owner);
        }

        // Current site is accessible. Allow through.
        return;
    }

    public function augmentNewSiteTreeItem(&$item)
    {
        $request = Controller::curr()->getRequest();
        $item->SubsiteID = $request->postVar('SubsiteID') ?: SubsiteState::singleton()->getSubsiteId();
    }

    public function onAfterSave($record)
    {
        if ($record->hasMethod('NormalRelated') && ($record->NormalRelated() || $record->ReverseRelated())) {
            $this->owner->response->addHeader(
                'X-Status',
                rawurlencode(_t(__CLASS__ . '.Saved', 'Saved, please update related pages.'))
            );
        }
    }

    /**
     * @param array $data
     * @param Form $form
     */
    public function copytosubsite($data, $form)
    {
        $page = DataObject::get_by_id(SiteTree::class, $data['ID']);
        $subsite = DataObject::get_by_id(Subsite::class, $data['CopyToSubsiteID']);
        $includeChildren = (isset($data['CopyToSubsiteWithChildren'])) ? $data['CopyToSubsiteWithChildren'] : false;

        $newPage = $page->duplicateToSubsite($subsite->ID, $includeChildren);
        $response = $this->owner->getResponse();
        $response->addHeader('X-Reload', true);

        return $this->owner->redirect(Controller::join_links(
            $this->owner->Link('show'),
            $newPage->ID
        ));
    }
}
