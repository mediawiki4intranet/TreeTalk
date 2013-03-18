<?php

$messages = array();

/* Message documentation */

$messages['qqq'] = array(
    'treetalk-email-subject' => 'Message subject for TreeTalk comment notifications. Parsed as wikitext. Parameters are the same as in treetalk-email-body',
    'treetalk-email-from' => '"From" address name for TreeTalk comment notifications. Not parsed, no arguments.',
    'treetalk-email-unsubscribe' => 'Unsubscribe link text for users who can unsubscribe from TreeTalk comment notifications. Treated as HTML text, not parsed. Parameters:
* $1: title to unsubscribe from
* $2: full URL to unsubscribe action',
    'treetalk-email-body' => 'Message body for TreeTalk comment notifications. Parsed as wikitext. Arguments:
* $1: new comment author
* $2: subject subpage name
* $3: talk namespace text
* $4: full subject title
* $5: full talk title
* $6: new comment text
* $7: anchor for new comment
* $8: responded comment author, if any
* $9: responded comment text, if any',
);

/* English */

$messages['en'] = array(
    'treetalk-email-subject' => '[$3] $1 - new comment to $2',
    'treetalk-email-from' => 'MediaWiki talk',
    'treetalk-email-body' => '
A new comment was left by [[User:$1|$1]] {{#if:$8|in reply to [[User:$8|$8]]\'s comment to article [[:$4|$2]]:

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
$9
</div>

The reply was:|for article [[:$4|$2]]:}}

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
$6
</div>

Possible actions:

* [[:$5#$7|View the talk page]] of $2 and/or reply to this comment.
* [[:$4|Read the article]] $2.
',
    'treetalk-email-unsubscribe' => '<p><a href="$2">Unsubscribe</a> from comments to $1.</p>',
);

/* Russian */

$messages['ru'] = array(
    'treetalk-email-subject' => '[$3] $1 - новый комментарий к $2',
    'treetalk-email-from' => 'Обсуждения MediaWiki',
    'treetalk-email-body' => '
Пользователь [[User:$1|$1]] {{#if:$8|ответил на комментарий, оставленный [[User:$8|$8]] к статье [[:$4|$2]]:

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
$9
</div>

Ответ был таким:|оставил новый комментарий к статье [[:$4|$2]]:}}

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
$6
</div>

Доступные действия:

* [[:$5#$7|Просмотреть страницу обсуждения]] статьи $2 и/или ответить на этот комментарий.
* [[:$4|Прочитать статью]] $2.
',
    'treetalk-email-unsubscribe' => '<p><a href="$2">Отписаться</a> от комментариев к $1.</p>',
);
