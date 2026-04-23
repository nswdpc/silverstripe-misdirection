<?php

namespace nglasl\misdirection;

use Codem\Utilities\HTML5\UrlField;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTP;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\SelectionGroup;
use SilverStripe\Forms\SelectionGroup_Item;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use Symbiote\Multisites\Multisites;

/**
 *	Simple and regular expression link redirection definitions.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @property ?string $LinkType
 * @property ?string $MappedLink
 * @property bool $IncludesHostname
 * @property int $Priority
 * @property ?string $RedirectType
 * @property ?string $RedirectLink
 * @property int $RedirectPageID
 * @property int $ResponseCode
 * @property ?string $HostnameRestriction
 */
class LinkMapping extends DataObject
{
    private static string $table_name = 'LinkMapping';

    private static string $singular_name = 'Redirect record';

    private static string $plural_name = 'Redirect records';

    /**
     *	Manually define the redirect page relationship when the CMS module is not present.
     */
    private static array $db = [
        'LinkType' => "Enum('Simple, Regular Expression', 'Simple')",
        'MappedLink' => 'Varchar(255)',
        'IncludesHostname' => 'Boolean',
        'Priority' => 'Int',
        'RedirectType' => "Enum('Link, Page', 'Link')",
        'RedirectLink' => 'Varchar(255)',
        'RedirectPageID' => 'Int',
        'ResponseCode' => 'Int',
        'HostnameRestriction' => 'Varchar(255)'
    ];

    /**
     * @inheritdoc
     */
    private static array $indexes = [
        'LinkType' => true,
        'MappedLink' => true,
        'IncludesHostname' => true,
        'Priority' => true,
        'RedirectType' => true,
        'RedirectLink' => true,
        'RedirectPageID' => true,
        'HostnameRestriction' => true
    ];

    private static array $defaults = [
        'ResponseCode' => 301
    ];

    /**
     *	Make sure the link mappings are only ordered by priority and specificity when matching.
     */
    private static string $default_sort = 'ID DESC';

    private static array $searchable_fields = [
        'MappedLink',
        'LinkType',
        'Priority',
        'RedirectType',
        'IncludesHostname'
    ];

    private static array $summary_fields = [
        'MappedLink',
        'LinkSummary',
        'IncludesHostname',
        'Priority',
        'RedirectTypeSummary',
        'RedirectPageTitle'
    ];

    private static array $field_labels = [
        'MappedLink' => 'Source link',
        'LinkSummary' => 'Redirection',
        'Priority' => 'Link priority',
        'IncludesHostname' => 'Includes domain?',
        'RedirectTypeSummary' => 'Redirect Type',
        'RedirectPageTitle' => 'Redirect Page Title'
    ];

    /**
     *	Make sure previous link mappings take precedence.
     */
    private static string $priority = 'ASC';

    /**
     *	Keep track of the initial URL for regular expression pattern replacement.
     *
     */
    private string $matchedURL;

    public function setMatchedURL(string $matchedURL)
    {
        $this->matchedURL = $matchedURL;
    }

    #[\Override]
    public function canView($member = null)
    {
        return true;
    }

    #[\Override]
    public function canEdit($member = null)
    {
        return (
            Permission::checkMember($member, 'ADMIN') ||
            Permission::checkMember($member, 'CMS_ACCESS_nglasl\misdirection\MisdirectionAdmin')
        );
    }

    #[\Override]
    public function canCreate($member = null, $context = [])
    {
        return (
            Permission::checkMember($member, 'ADMIN') ||
            Permission::checkMember($member, 'CMS_ACCESS_nglasl\misdirection\MisdirectionAdmin')
        );
    }

    #[\Override]
    public function canDelete($member = null)
    {
        return (
            Permission::checkMember($member, 'ADMIN') ||
            Permission::checkMember($member, 'CMS_ACCESS_nglasl\misdirection\MisdirectionAdmin')
        );
    }

    /**
     *	Print the mapped URL associated with this link mapping.
     */

    #[\Override]
    public function getTitle(): ?string
    {
        return $this->MappedLink;
    }

    #[\Override]
    public function getCMSFields()
    {

        $fields = parent::getCMSFields();
        Requirements::css('nglasl/silverstripe-misdirection: client/css/misdirection.css');

        // Remove any fields that are not required in their default state.
        $fields->removeByName([
            'MappedLink',
            'IncludesHostname',
            'Priority',
            'RedirectType',
            'RedirectLink',
            'RedirectPageID',
            'ResponseCode',
            'HostnameRestriction'
        ]);

        // Update any fields that are displayed.
        $linkTypeField = $fields->dataFieldByName('LinkType');
        if ($linkTypeField) {
            $linkTypeField->addExtraClass('link-type')
                ->setTitle(_t(self::class . '.TYPE_OF_LINK', 'Type of redirect'));
        }

        // Instantiate the required fields.
        $linkCompositeField = CompositeField::create()
            ->setTitle(_t(self::class . '.SOURCE_OF_REDIRECT', 'The source of the redirect'));
        $fields->addFieldToTab(
            'Root.Main',
            $linkCompositeField
        );

        // Retrieve the mapped link configuration as a single grouping.
        $linkCompositeField->push(
            TextField::create(
                'MappedLink',
                _t(self::class . '.MAPPED_LINK_TITLE', 'Link')
            )->addExtraClass('mapped-link')
                ->setDescription(
                    htmlspecialchars(_t(self::class . '.MAPPED_LINK_DESCRIPTION', "Add a path, e.g. 'the-page'. If a domain is required in the redirect, add the domain and the path, e.g. 'example.com/the-page"))
                )
        );
        $linkCompositeField->push(
            CheckboxField::create(
                'IncludesHostname',
                _t(self::class . '.INCLUDE_HOSTNAME', 'Includes domain?')
            )->setDescription(
                htmlspecialchars(_t(self::class . '.INCLUDE_HOSTNAME_DESCRIPTION', "Select if the link includes a domain e.g 'example.com' before the link path. Use this option if you want to redirect on a specific domain."))
            )
        );

        // Generate the 1 - 10 priority selection.
        $maxPriority = 10;
        $range = [];
        for ($iteration =  $maxPriority; $iteration > 0; $iteration--) {
            $range[$iteration] = (string) $iteration;
        }

        $linkCompositeField->push(
            DropdownField::create(
                'Priority',
                _t(self::class . '.REDIRECTION_PRIORITY', 'Priority'),
                $range
            )->setDescription(
                _t(self::class . '.HIGHEST_PRIORITY', 'Higher priority links will be preferred')
            )
        );

        // Retrieve the redirection configuration as a single grouping.
        $targetCompositeField = CompositeField::create()
            ->setTitle(_t(self::class . '.TARGET_OF_REDIRECT', 'The target of the redirect'));

        $redirectLinkField = UrlField::create(
            'RedirectLink',
            _t(self::class . '.TARGET_OF_REDIRECT_LINK', 'The website address')
        )->addExtraClass('redirect-link')
            ->restrictToHttp();// validation

        // Allow redirect page configuration when the CMS module is present.
        if (class_exists(SiteTree::class)) {

            // Allow redirect type configuration.
            if (!$this->RedirectType) {
                // Initialise the default redirect type.
                $this->RedirectType = 'Link';
            }

            $targetCompositeField->push(
                SelectionGroup::create(
                    'RedirectType',
                    [
                        SelectionGroup_Item::create(
                            'Link',
                            $redirectLinkField,
                            _t(self::class . '.TO_URL', 'An external web page on another website')
                        ),
                        SelectionGroup_Item::create(
                            'Page',
                            TreeDropdownField::create(
                                'RedirectPageID',
                                '',
                                SiteTree::class
                            ),
                            _t(self::class . '.TO_PAGE', 'A page on this website')
                        )
                    ]
                )
            );
        } else {
            // External links only
            $targetCompositeField->push($redirectLinkField);
        }

        $fields->addFieldToTab(
            'Root.Main',
            $targetCompositeField
        );

        // Retrieve the response code selection.
        $responses = Config::inst()->get(MisDirectionRequestProcessor::class, 'status_codes');
        $selection = [];
        foreach ($responses as $code => $description) {
            if (($code >= 300) && ($code < 400)) {
                $selection[$code] = "{$code}: {$description}";
            }
        }

        $linkCompositeField->push(
            DropdownField::create(
                'ResponseCode',
                _t(self::class . '.RESPONSE_CODE', 'Redirect code'),
                $selection
            )->setDescription(
                htmlspecialchars(_t(self::class . '.RESPONSE_CODE_DESCRIPTION', "This provides helpful information to browsers and search engines about the reason for the redirect. Use the default '301' if unsure."))
            )
        );

        // The optional hostname restriction is now deprecated.
        if ($this->HostnameRestriction) {
            $fields->addFieldToTab(
                'Root.Optional',
                TextField::create(
                    'HostnameRestriction'
                )
            );
        }

        // Allow extension customisation.
        $this->extend('updateLinkMappingCMSFields', $fields);
        return $fields;
    }

    #[\Override]
    public function validate()
    {
        $result = parent::validate();
        // Determine whether a regular expression mapping is possible to match against.
        if ($result->isValid()
            && ($this->LinkType === 'Regular Expression')
            && (is_null($this->MappedLink) || @preg_match("%" . preg_quote((string)$this->MappedLink, "%") . "%", '') === false)
        ) {
            $result->addError('Invalid regular expression!');
        }

        // Allow extension customisation.
        $this->extend('validateLinkMapping', $result);
        return $result;
    }

    /**
     *	Unify any URLs that may have been defined.
     */
    #[\Override]
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->MappedLink = MisdirectionService::unify_URL($this->MappedLink ?? '');
        $this->RedirectLink = trim($this->RedirectLink ?? '', ' ?/');
        $this->HostnameRestriction = MisdirectionService::unify_URL($this->HostnameRestriction ?? '');
    }

    /**
     *	Retrieve the page associated with this link mapping redirection.
     *  @phpstan-ignore class.notFound
     */
    public function getRedirectPage(): ?SiteTree
    {
        return (class_exists(SiteTree::class) && $this->RedirectPageID) ? SiteTree::get()->byID($this->RedirectPageID) : null;
    }

    /**
     *	Retrieve the redirection URL.
     *
     */
    public function getLink(): ?string
    {

        if ($this->RedirectType === 'Page' && class_exists(SiteTree::class)) {
            // Determine the home page URL when appropriate.
            if (($page = $this->getRedirectPage()) && ($link = ($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), MisdirectionService::getHomeSegment()) : $page->Link())) {
                // This is to support multiple sites, where the absolute page URLs are treated as relative.
                return MisdirectionService::is_external_URL($link) ? ltrim((string) $link, '/') : $link;
            }
        } elseif ($link = (($this->LinkType === 'Regular Expression') && $this->matchedURL) ? preg_replace("%{$this->MappedLink}%i", (string) $this->RedirectLink, $this->matchedURL) : $this->RedirectLink) {
            // Apply the regular expression pattern replacement.
            // When appropriate, prepend the base URL to match a page redirection.
            $prepended = Controller::join_links(Director::baseURL(), $link);
            if (MisdirectionService::is_external_URL($link)) {
                return class_exists(Multisites::class) ? HTTP::setGetVar('misdirected', '1', $link) : $link;
            } elseif (MisdirectionService::is_external_URL($prepended)) {
                // This is needed, otherwise infinitely recursive mappings won't be detected in advance.
                return $link;
            } else {
                return $prepended;
            }
        }

        // No redirection URL has been found.
        return null;
    }

    /**
     *	Retrieve the redirection hostname.
     *
     */
    public function getLinkHost(): ?string
    {

        if ($this->RedirectType === 'Page' && class_exists(SiteTree::class)) {
            // Determine the home page URL when appropriate.
            if (($page = $this->getRedirectPage()) && ($link = ($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), MisdirectionService::getHomeSegment()) : $page->Link())) {
                // Determine whether a redirection hostname exists.
                return MisdirectionService::is_external_URL($link) ? parse_url((string) $link, PHP_URL_HOST) : null;
            }
        } elseif ($link = (($this->LinkType === 'Regular Expression') && $this->matchedURL) ? preg_replace("%{$this->MappedLink}%i", (string) $this->RedirectLink, $this->matchedURL) : $this->RedirectLink) {
            // Apply the regular expression pattern replacement.
            // Determine whether a redirection hostname exists.
            return MisdirectionService::is_external_URL($link) ? parse_url($link, PHP_URL_HOST) : null;
        }

        // No redirection hostname has been found.
        return null;
    }

    /**
     *	Retrieve the redirection URL for display purposes.
     *
     */
    public function getLinkSummary(): string
    {
        return ($link = $this->getLink()) ? trim($link, ' ?/') : '-';
    }

    /**
     *	Retrieve the redirection type for display purposes.
     *
     */
    public function getRedirectTypeSummary(): string
    {
        return $this->RedirectType ?: '-';
    }

    /**
     *	Retrieve the page title associated with this link mapping redirection.
     *
     */
    public function getRedirectPageTitle(): string
    {
        return (($this->RedirectType === 'Page' && class_exists(SiteTree::class)) && ($page = $this->getRedirectPage())) ? $page->Title : '-';
    }

    /**
     *	Determine if the link mapping is live on the current stage.
     *
     */
    public function isLive(): string
    {
        return ($this->RedirectType === 'Page') ? ($this->getRedirectPage() ? 'true' : 'false') : '-';
    }

}
