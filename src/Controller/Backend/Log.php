<?php
namespace Bolt\Controller\Backend;

use Bolt\Translation\Translator as Trans;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend controller for logging routes.
 *
 * Prior to v2.3 this functionality primarily existed in the monolithic
 * Bolt\Controllers\Backend class.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Log extends BackendBase
{
    protected function addRoutes(ControllerCollection $c)
    {
        $c->get('/changelog', 'changeOverview')
            ->bind('changelog');

        $c->get('/changelog/{contenttype}/{contentid}/{id}', 'changeRecord')
            ->assert('id', '\d*')
            ->bind('changelogrecordsingle');

        $c->get('/changelog/{contenttype}/{contentid}', 'changeRecordListing')
            ->value('contentid', '0')
            ->value('contenttype', '')
            ->bind('changelogrecordall');

        $c->get('/systemlog', 'systemOverview')
            ->bind('systemlog');
    }

    /**
     * Change log overview route.
     *
     * @param Request $request The Symfony Request
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function changeOverview(Request $request)
    {
        $action = $request->query->get('action');

        if ($action == 'clear') {
            $this->manager()->clear('change');
            $this->flashes()->success(Trans::__('The change log has been cleared.'));

            return $this->redirectToRoute('changelog');
        } elseif ($action == 'trim') {
            $this->manager()->trim('change');
            $this->flashes()->success(Trans::__('The change log has been trimmed.'));

            return $this->redirectToRoute('changelog');
        }

        $activity = $this->manager()->getActivity('change', 16);

        return $this->render('activity/changelog.twig', ['entries' => $activity]);
    }

    /**
     * Show a single change log entry.
     *
     * @param Request $request     The Symfony Request
     * @param string  $contenttype The content type slug
     * @param integer $contentid   The content ID
     * @param integer $id          The changelog entry ID
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function changeRecord(Request $request, $contenttype, $contentid, $id)
    {
        $entry = $this->changeLog()->getChangelogEntry($contenttype, $contentid, $id);
        if (empty($entry)) {
            $error = Trans::__("The requested changelog entry doesn't exist.");

            $this->abort(Response::HTTP_NOT_FOUND, $error);
        }
        $prev = $this->changeLog()->getPrevChangelogEntry($contenttype, $contentid, $id);
        $next = $this->changeLog()->getNextChangelogEntry($contenttype, $contentid, $id);

        $context = [
            'contenttype' => ['slug' => $contenttype],
            'entry'       => $entry,
            'next_entry'  => $next,
            'prev_entry'  => $prev
        ];

        return $this->render('changelog/changelogrecordsingle.twig', $context);
    }

    /**
     * Show a list of changelog entries.
     *
     * @param Request $request     The Symfony Request
     * @param string  $contenttype The content type slug
     * @param integer $contentid   The content ID
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function changeRecordListing(Request $request, $contenttype, $contentid)
    {
        // We have to handle three cases here:
        // - $contenttype and $contentid given: get changelog entries for *one* content item
        // - only $contenttype given: get changelog entries for all items of that type
        // - neither given: get all changelog entries

        // But first, let's get some pagination stuff out of the way.
        $limit = 5;
        if ($page = $request->get('page')) {
            if ($page === 'all') {
                $limit = null;
                $page = null;
            } else {
                $page = intval($page);
            }
        } else {
            $page = 1;
        }

        // Some options that are the same for all three cases
        $options = [
            'order'     => 'date',
            'direction' => 'DESC'
        ];
        if ($limit) {
            $options['limit'] = $limit;
        }
        if ($page > 0 && $limit) {
            $options['offset'] = ($page - 1) * $limit;
        }

        $content = null;

        // Now here things diverge.
        if (empty($contenttype)) {
            // Case 1: No content type given, show from *all* items.
            // This is easy:
            $title = Trans::__('All content types');
            $logEntries = $this->changeLog()->getChangelog($options);
            $itemcount = $this->changeLog()->countChangelog();
        } else {
            // We have a content type, and possibly a contentid.
            $contenttypeObj = $this->getContentType($contenttype);
            if ($contentid) {
                $content = $this->getContent($contenttype, ['id' => $contentid, 'hydrate' => false]);
                $options['contentid'] = $contentid;
            }
            // Getting a slice of data and the total count
            $logEntries = $this->changeLog()->getChangelogByContentType($contenttype, $options);
            $itemcount = $this->changeLog()->countChangelogByContentType($contenttype, $options);

            // The page title we're sending to the template depends on a few
            // things: if no contentid is given, we'll use the plural form
            // of the content type; otherwise, we'll derive it from the
            // changelog or content item itself.
            if ($contentid) {
                if ($content) {
                    // content item is available: get the current title
                    $title = $content->getTitle();
                } else {
                    // content item does not exist (anymore).
                    if (empty($logEntries)) {
                        // No item, no entries - phew. Content type name and ID
                        // will have to do.
                        $title = $contenttypeObj['singular_name'] . ' #' . $contentid;
                    } else {
                        // No item, but we can use the most recent title.
                        $title = $logEntries[0]['title'];
                    }
                }
            } else {
                // We're displaying all changes for the entire content type,
                // so the plural name is most appropriate.
                $title = $contenttypeObj['name'];
            }
        }

        // Now calculate number of pages.
        // We can't easily do this earlier, because we only have the item count
        // now.
        // Note that if either $limit or $pagecount is empty, the template will
        // skip rendering the pager.
        $pagecount = $limit ? ceil($itemcount / $limit) : null;

        $context = [
            'contenttype' => ['slug' => $contenttype],
            'entries'     => $logEntries,
            'content'     => $content,
            'title'       => $title,
            'currentpage' => $page,
            'pagecount'   => $pagecount
        ];

        return $this->render('changelog/changelogrecordall.twig', $context);
    }

    /**
     * System log overview route
     *
     * @param Request $request The Symfony Request
     *
     * @return \Bolt\Response\BoltResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function systemOverview(Request $request)
    {
        $action = $request->query->get('action');

        if ($action == 'clear') {
            $this->manager()->clear('system');
            $this->flashes()->success(Trans::__('The system log has been cleared.'));

            return $this->redirectToRoute('systemlog');
        } elseif ($action == 'trim') {
            $this->manager()->trim('system');
            $this->flashes()->success(Trans::__('The system log has been trimmed.'));

            return $this->redirectToRoute('systemlog');
        }

        $level = $request->query->get('level');
        $context = $request->query->get('context');

        $activity = $this->manager()->getActivity('system', 16, $level, $context);

        return $this->render('activity/systemlog.twig', ['entries' => $activity]);
    }

    /**
     * @return \Bolt\Logger\ChangeLog
     */
    protected function changeLog()
    {
        return $this->app['logger.manager.change'];
    }

    /**
     * @return \Bolt\Logger\Manager
     */
    protected function manager()
    {
        return $this->app['logger.manager'];
    }
}
