-- Used to track and edit comments
create table if not exists /*$wgDBprefix*/treetalk_comments (
    tt_id int unsigned not null auto_increment primary key,
    tt_page int unsigned not null,
    tt_user_text varchar(255) binary not null,
    tt_timestamp binary(14) not null,
    tt_text blob not null,
    key (tt_page, tt_user_text, tt_timestamp)
) /*$wgDBTableOptions*/;

-- Last visit timestamps - used to track new comments
create table if not exists /*$wgDBprefix*/treetalk_pageview (
    tv_page int unsigned not null,
    tv_user int unsigned not null,
    tv_timestamp binary(14) not null,
    primary key (tv_page, tv_user)
) /*$wgDBTableOptions*/;

-- Custom subscription - used to allow hierarchical [un]subscribing
create table if not exists /*$wgDBprefix*/treetalk_subscribers (
    ts_type tinyint(1) not null, -- anything=0 namespace=1 page=2
    ts_page int unsigned not null,
    ts_user int unsigned not null,
    ts_yes tinyint(1) not null,
    primary key (ts_page, ts_user)
) /*$wgDBTableOptions*/;
