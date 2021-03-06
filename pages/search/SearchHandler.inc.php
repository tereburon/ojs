<?php

/**
 * @file pages/search/SearchHandler.inc.php
 *
 * Copyright (c) 2014-2015 Simon Fraser University Library
 * Copyright (c) 2003-2015 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SearchHandler
 * @ingroup pages_search
 *
 * @brief Handle site index requests.
 */

import('classes.search.ArticleSearch');
import('classes.handler.Handler');

class SearchHandler extends Handler {
	/**
	 * Constructor
	 **/
	function SearchHandler() {
		parent::Handler();
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		import('classes.security.authorization.OjsJournalMustPublishPolicy');
		$this->addPolicy(new OjsJournalMustPublishPolicy($request));

		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Show the search form
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function index($args, $request) {
		$this->validate(null, $request);
		$this->search($args, $request);
	}

	/**
	 * Private function to transmit current filter values
	 * to the template.
	 * @param $request PKPRequest
	 * @param $templateMgr TemplateManager
	 * @param $searchFilters array
	 */
	function _assignSearchFilters($request, &$templateMgr, $searchFilters) {
		// Get the journal id (if any).
		$journal =& $searchFilters['searchJournal'];
		$journalId = ($journal ? $journal->getId() : null);
		$searchFilters['searchJournal'] = $journalId;

		// Assign all filters except for dates which need special treatment.
		$templateSearchFilters = array();
		foreach($searchFilters as $filterName => $filterValue) {
			if (in_array($filterName, array('fromDate', 'toDate'))) continue;
			$templateSearchFilters[$filterName] = $filterValue;
		}

		// Find out whether we have active/empty filters.
		$hasActiveFilters = false;
		$hasEmptyFilters = false;
		foreach($templateSearchFilters as $filterName => $filterValue) {
			// The main query and journal selector will always be displayed
			// apart from other filters.
			if (in_array($filterName, array('query', 'searchJournal', 'siteSearch'))) continue;
			if (empty($filterValue)) {
				$hasEmptyFilters = true;
			} else {
				$hasActiveFilters = true;
			}
		}

		// Assign the filters to the template.
		$templateMgr->assign($templateSearchFilters);

		// Special case: publication date filters.
		foreach(array('From', 'To') as $fromTo) {
			$month = $request->getUserVar("date${fromTo}Month");
			$day = $request->getUserVar("date${fromTo}Day");
			$year = $request->getUserVar("date${fromTo}Year");
			if (empty($year)) {
				$date = '--';
				$hasEmptyFilters = true;
			} else {
				$defaultMonth = ($fromTo == 'From' ? 1 : 12);
				$defaultDay = ($fromTo == 'From' ? 1 : 31);
				$date = date(
					'Y-m-d H:i:s',
					mktime(
						0, 0, 0, empty($month) ? $defaultMonth : $month,
						empty($day) ? $defaultDay : $day, $year
					)
				);
				$hasActiveFilters = true;
			}
			$templateMgr->assign(array(
				"date${fromTo}Month" => $month,
				"date${fromTo}Day" => $day,
				"date${fromTo}Year" => $year,
				"date${fromTo}" => $date
			));
		}

		// Assign filter flags to the template.
		$templateMgr->assign(compact('hasEmptyFilters', 'hasActiveFilters'));

		// Assign the year range.
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$yearRange = $publishedArticleDao->getArticleYearRange($journalId);
		$startYear = '-' . (date('Y') - substr($yearRange[1], 0, 4));
		if (substr($yearRange[0], 0, 4) >= date('Y')) {
			$endYear = '+' . (substr($yearRange[0], 0, 4) - date('Y'));
		} else {
			$endYear = (substr($yearRange[0], 0, 4) - date('Y'));
		}
		$templateMgr->assign(compact('startYear', 'endYear'));

		// Assign journal options.
		if ($searchFilters['siteSearch']) {
			$journalDao = DAORegistry::getDAO('JournalDAO');
			$journals =& $journalDao->getTitles(true);
			$templateMgr->assign('journalOptions', array('' => AppLocale::Translate('search.allJournals')) + $journals);
		}
	}

	/**
	 * Show the search form
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function search($args, $request) {
		$this->validate(null, $request);

		// Get and transform active filters.
		$articleSearch = new ArticleSearch();
		$searchFilters = $articleSearch->getSearchFilters($request);
		$keywords = $articleSearch->getKeywordsFromSearchFilters($searchFilters);

		// Get the range info.
		$rangeInfo = $this->getRangeInfo($request, 'search');

		// Retrieve results.
		$error = '';
		$results = $articleSearch->retrieveResults(
			$request, $searchFilters['searchJournal'], $keywords, $error,
			$searchFilters['fromDate'], $searchFilters['toDate'],
			$rangeInfo
		);

		// Prepare and display the search template.
		$this->setupTemplate($request);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->setCacheability(CACHEABILITY_NO_STORE);

		// Result set ordering options.
		$orderByOptions = $articleSearch->getResultSetOrderingOptions($request);
		$templateMgr->assign('searchResultOrderOptions', $orderByOptions);
		$orderDirOptions = $articleSearch->getResultSetOrderingDirectionOptions();
		$templateMgr->assign('searchResultOrderDirOptions', $orderDirOptions);

		// Result set ordering selection.
		list($orderBy, $orderDir) = $articleSearch->getResultSetOrdering($request);
		$templateMgr->assign('orderBy', $orderBy);
		$templateMgr->assign('orderDir', $orderDir);

		// Similar documents.
		$templateMgr->assign('simDocsEnabled', true);

		// Result set display.
		$templateMgr->assign('jsLocaleKeys', array('search.noKeywordError'));
		$this->_assignSearchFilters($request, $templateMgr, $searchFilters);
		$templateMgr->assign('results', $results);
		$templateMgr->assign('error', $error);
		$templateMgr->display('search/search.tpl');
	}

	/**
	 * Redirect to a search query that shows documents
	 * similar to the one identified by an article id in the
	 * request.
	 * @param $args array
	 * @param $request Request
	 */
	function similarDocuments($args, &$request) {
		$this->validate(null, $request);

		// Retrieve the (mandatory) ID of the article that
		// we want similar documents for.
		$articleId = $request->getUserVar('articleId');
		if (!is_numeric($articleId)) {
			$request->redirect(null, 'search');
		}

		// Check whether a search plugin provides terms for a similarity search.
		$articleSearch = new ArticleSearch();
		$searchTerms = $articleSearch->getSimilarityTerms($articleId);

		// Redirect to a search query with the identified search terms (if any).
		if (empty($searchTerms)) {
			$searchParams = null;
		} else {
			$searchParams = array('query' => implode(' ', $searchTerms));
		}
		$request->redirect(null, 'search', 'search', null, $searchParams);
	}

	/**
	 * Show index of published articles by author.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function authors($args, $request) {
		$this->validate(null, $request);
		$this->setupTemplate($request);

		$journal = $request->getJournal();

		$authorDao = DAORegistry::getDAO('AuthorDAO');

		if (isset($args[0]) && $args[0] == 'view') {
			// View a specific author
			$firstName = $request->getUserVar('firstName');
			$middleName = $request->getUserVar('middleName');
			$lastName = $request->getUserVar('lastName');
			$affiliation = $request->getUserVar('affiliation');
			$country = $request->getUserVar('country');

			$publishedArticles = $authorDao->getPublishedArticlesForAuthor($journal?$journal->getId():null, $firstName, $middleName, $lastName, $affiliation, $country);

			// Load information associated with each article.
			$journals = array();
			$issues = array();
			$sections = array();
			$issuesUnavailable = array();

			$issueDao = DAORegistry::getDAO('IssueDAO');
			$sectionDao = DAORegistry::getDAO('SectionDAO');
			$journalDao = DAORegistry::getDAO('JournalDAO');

			foreach ($publishedArticles as $article) {
				$articleId = $article->getId();
				$issueId = $article->getIssueId();
				$sectionId = $article->getSectionId();
				$journalId = $article->getJournalId();

				if (!isset($issues[$issueId])) {
					import('classes.issue.IssueAction');
					$issue = $issueDao->getById($issueId);
					$issues[$issueId] = $issue;
					$issueAction = new IssueAction();
					$issuesUnavailable[$issueId] = $issueAction->subscriptionRequired($issue) && (!$issueAction->subscribedUser($journal, $issueId, $articleId) && !$issueAction->subscribedDomain($journal, $issueId, $articleId));
				}
				if (!isset($journals[$journalId])) {
					$journals[$journalId] = $journalDao->getById($journalId);
				}
				if (!isset($sections[$sectionId])) {
					$sections[$sectionId] = $sectionDao->getById($sectionId, $journalId, true);
				}
			}

			if (empty($publishedArticles)) {
				$request->redirect(null, $request->getRequestedPage());
			}

			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('publishedArticles', $publishedArticles);
			$templateMgr->assign('issues', $issues);
			$templateMgr->assign('issuesUnavailable', $issuesUnavailable);
			$templateMgr->assign('sections', $sections);
			$templateMgr->assign('journals', $journals);
			$templateMgr->assign('firstName', $firstName);
			$templateMgr->assign('middleName', $middleName);
			$templateMgr->assign('lastName', $lastName);
			$templateMgr->assign('affiliation', $affiliation);

			$countryDao = DAORegistry::getDAO('CountryDAO');
			$country = $countryDao->getCountry($country);
			$templateMgr->assign('country', $country);

			$templateMgr->display('search/authorDetails.tpl');
		} else {
			// Show the author index
			$searchInitial = $request->getUserVar('searchInitial');
			$rangeInfo = $this->getRangeInfo($request, 'authors');

			$authors = $authorDao->getAuthorsAlphabetizedByJournal(
				isset($journal)?$journal->getId():null,
				$searchInitial,
				$rangeInfo
			);

			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('searchInitial', $request->getUserVar('searchInitial'));
			$templateMgr->assign('alphaList', explode(' ', __('common.alphaList')));
			$templateMgr->assign('authors', $authors);
			$templateMgr->display('search/authorIndex.tpl');
		}
	}

	/**
	 * Display categories.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function categories($args, $request) {
		$this->validate(null, $request);
		$this->setupTemplate($request);

		$site = $request->getSite();
		$journal = $request->getJournal();

		$categoryDao = DAORegistry::getDAO('CategoryDAO');
		$cache = $categoryDao->getCache();

		if ($journal || !$site->getSetting('categoriesEnabled') || !$cache) {
			$request->redirect('index');
		}

		// Sort by category name
		uasort($cache, create_function('$a, $b', '$catA = $a[\'category\']; $catB = $b[\'category\']; return strcasecmp($catA->getLocalizedName(), $catB->getLocalizedName());'));

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('categories', $cache);
		$templateMgr->display('search/categories.tpl');
	}

	/**
	 * Display category contents.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function category($args, $request) {
		$categoryId = (int) array_shift($args);

		$this->validate(null, $request);
		$this->setupTemplate($request);

		$site = $request->getSite();
		$journal = $request->getJournal();

		$categoryDao = DAORegistry::getDAO('CategoryDAO');
		$cache = $categoryDao->getCache();

		if ($journal || !$site->getSetting('categoriesEnabled') || !$cache || !isset($cache[$categoryId])) {
			$request->redirect('index');
		}

		$journals =& $cache[$categoryId]['journals'];
		$category =& $cache[$categoryId]['category'];

		// Sort by journal name
		uasort($journals, create_function('$a, $b', 'return strcasecmp($a->getLocalizedTitle(), $b->getLocalizedTitle());'));

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('journals', $journals);
		$templateMgr->assign('category', $category);
		$templateMgr->assign('journalFilesPath', $request->getBaseUrl() . '/' . Config::getVar('files', 'public_files_dir') . '/journals/');
		$templateMgr->display('search/category.tpl');
	}

	/**
	 * Setup common template variables.
	 * @param $request PKPRequest
	 */
	function setupTemplate($request) {
		parent::setupTemplate($request);
		$templateMgr = TemplateManager::getManager($request);
		$journal = $request->getJournal();
		if (!$journal || !$journal->getSetting('restrictSiteAccess')) {
			$templateMgr->setCacheability(CACHEABILITY_PUBLIC);
		}
	}
}

?>
