<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\PartialResultException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The history behind an HTTP endpoint.
 *
 * Two things worth copying from here rather than the happy path:
 *
 * - **the reader does not swallow failures.** Unlike the writer, whose whole job is
 *   to never take a business operation down, a read either answers or raises. Map
 *   the exceptions to the statuses your API promises.
 * - **`PartialResultException` is the failure nobody expects.** When a shard fails
 *   or a search times out, Elasticsearch answers with what it has and says so; the
 *   reader refuses that answer rather than presenting a short page as the history.
 *   A screen may well prefer to show what there is — catching it is a decision, and
 *   this is where the decision belongs. An export should not catch it.
 */
final class HistoryController
{
    public function __construct(private readonly AuditReader $reader)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            // Building the query is inside the try, not before it: the parts that
            // read user input validate it as they go — a limit past the maximum, a
            // cursor from somebody else's query — and those are the same mistake as
            // a bad filter, answered with the same 400. Left outside, a pasted
            // cursor is a 500.
            $query = AuditQuery::for($request->query->getString('objectType', 'order'))
                ->page($request->query->getInt('page', 1), min(100, $request->query->getInt('limit', 20)));

            if (($id = $request->query->getString('objectId')) !== '') {
                $query = $query->withObjectId($id);
            }

            if (($cursor = $request->query->getString('cursor')) !== '') {
                // A cursor supersedes the page number: the two ways to page do not
                // mix, and the token carries the position.
                $query = $query->afterToken($cursor);
            }

            $page = $this->reader->find($query);
        } catch (InvalidQueryException $e) {
            // The caller's fault: a limit past the maximum, a cursor from another
            // query, an attribute name nothing will ever match.
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (IndexNotFoundException) {
            // Nothing has been written yet, or the index was rotated away.
            return new JsonResponse(['items' => [], 'pagination' => ['total' => 0]]);
        } catch (PartialResultException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 503);
        }

        // toArray() is the read-side shape: items with their extras, and the
        // pagination block including nextCursor.
        return new JsonResponse($page->toArray());
    }
}
