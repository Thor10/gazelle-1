<?php
$RequestTax = REQUEST_TAX;

// Minimum and default amount of upload to remove from the user when they vote.
// Also change in static/functions/requests.js
$MinimumVote = REQUEST_MIN * 1024 * 1024;

/*
 * This is the page that displays the request to the end user after being created.
 */

$request = (new Gazelle\Manager\Request)->findById((int)($_GET['id'] ?? 0));
if (is_null($request)) {
    json_die("failure");
}
$requestId = $request->id();

//First things first, lets get the data for the request.
$Request = Requests::get_request($requestId);

$userMan = new Gazelle\Manager\User;

$CategoryID = $Request['CategoryID'];
$Requestor = $userMan->findById($Request['UserID']);
$Filler = $userMan->findById($Request['FillerID']);
//Convenience variables
$IsFilled = !empty($Request['TorrentID']);
$CanVote = !$IsFilled && $Viewer->permitted('site_vote');

if ($CategoryID == 0) {
    $CategoryName = 'Unknown';
} else {
    $CategoryName = CATEGORY[$CategoryID - 1];
}

//Do we need to get artists?
if ($CategoryName == 'Music') {
    $ReleaseName = (new Gazelle\ReleaseType)->findNameById($Request['ReleaseType']);
}

//Votes time
$VoteCount = $request->userVotedTotal();
$UserCanEdit = (!$IsFilled && $Viewer->id() == $Request['UserID'] && $VoteCount < 2);
$CanEdit = ($UserCanEdit || $Viewer->permitted('site_moderate_requests'));

$JsonTopContributors = [];
foreach (array_slice($request->userVoteList($userMan), 0, 5) as $vote) {
    $JsonTopContributors[] = [
        'userId'   => $vote['user_id'],
        'userName' => $vote['user']->username(),
        'bounty'   => $vote['bounty'],
    ];
}

$commentPage = new Gazelle\Comment\Request($requestId, (int)($_GET['page'] ?? 1), (int)($_GET['post'] ?? 0));
$thread = $commentPage->load()->thread();

$authorCache = [];
$JsonRequestComments = [];
foreach ($thread as $Key => $Post) {
    [$PostID, $AuthorID, $AddedTime, $Body, $EditedUserID, $EditedTime, $EditedUsername] = array_values($Post);
    if (!isset($authorCache[$AuthorID])) {
        $authorCache[$AuthorID] = $userMan->findById($AuthorID);
    }
    $author = $authorCache[$AuthorID];
    $JsonRequestComments[] = [
        'postId'         => $PostID,
        'authorId'       => $AuthorID,
        'name'           => $author->username(),
        'donor'          => (new Gazelle\User\Privilege($author))->isDonor(),
        'warned'         => $author->isWarned(),
        'enabled'        => $author->isEnabled(),
        'class'          => $userMan->userclassName($author->primaryClass()),
        'addedTime'      => $AddedTime,
        'avatar'         => $author->avatar(),
        'bbBody'         => $Body,
        'comment'        => Text::full_format($Body),
        'editedUserId'   => $EditedUserID,
        'editedUsername' => $EditedUsername,
        'editedTime'     => $EditedTime
    ];
}

$JsonTags = [];
foreach ($Request['Tags'] as $Tag) {
    $JsonTags[] = $Tag;
}
json_print('success', [
    'requestId'       => $requestId,
    'requestorId'     => $Request['UserID'],
    'requestorName'   => $Requestor->username(),
    'isBookmarked'    => (new Gazelle\User\Bookmark($Viewer))->isRequestBookmarked($requestId),
    'requestTax'      => $RequestTax,
    'timeAdded'       => $Request['TimeAdded'],
    'canEdit'         => $CanEdit,
    'canVote'         => $CanVote,
    'minimumVote'     => $MinimumVote,
    'voteCount'       => $VoteCount,
    'lastVote'        => $Request['LastVote'],
    'topContributors' => $JsonTopContributors,
    'totalBounty'     => $request->bountyTotal(),
    'categoryId'      => $CategoryID,
    'categoryName'    => $CategoryName,
    'title'           => $Request['Title'],
    'year'            => (int)$Request['Year'],
    'image'           => $Request['Image'],
    'bbDescription'   => $Request['Description'],
    'description'     => Text::full_format($Request['Description']),
    'musicInfo'       => $CategoryName != "Music"
        ? null : Requests::get_artist_by_type($requestId),
    'catalogueNumber' => $Request['CatalogueNumber'],
    'releaseType'     => $Request['ReleaseType'],
    'releaseTypeName' => $ReleaseName,
    'bitrateList'     => preg_split('/\|/', $Request['BitrateList'], 0, PREG_SPLIT_NO_EMPTY),
    'formatList'      => preg_split('/\|/', $Request['FormatList'], 0, PREG_SPLIT_NO_EMPTY),
    'mediaList'       => preg_split('/\|/', $Request['MediaList'], 0, PREG_SPLIT_NO_EMPTY),
    'logCue'          => html_entity_decode($Request['LogCue']),
    'isFilled'        => $IsFilled,
    'fillerId'        => (int)$Request['FillerID'],
    'fillerName'      => is_null($Filler) ? '' : $Filler->username(),
    'torrentId'       => (int)$Request['TorrentID'],
    'timeFilled'      => $Request['TimeFilled'],
    'tags'            => $JsonTags,
    'comments'        => $JsonRequestComments,
    'commentPage'     => $commentPage->pageNum(),
    'commentPages'    => (int)ceil($commentPage->total() / TORRENT_COMMENTS_PER_PAGE),
    'recordLabel'     => $Request['RecordLabel'],
    'oclc'            => $Request['OCLC']
]);
