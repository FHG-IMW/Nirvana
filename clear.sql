truncate `cfiles_folder`;
truncate `comment`;
DELETE FROM `group_user` WHERE `user_id` > 1;
truncate `like`;
truncate `message`;
truncate `message_entry`;
truncate `nda_agreement`;
truncate `nda_model_chose`;
truncate `post`;
truncate `user_http_session`;
truncate `user_message`;
truncate `user_module`;
DELETE FROM `user_password` WHERE `user_id` > 1;

DELETE FROM `wall` WHERE `id` > 12;
DELETE FROM `wall_entry` WHERE `id` > 12;
truncate `space_user_role`;
truncate `extra_data_user`;
truncate `user_auth`;
truncate `user_card`;
truncate `user_follow`;
truncate `user_http_session`;
truncate `user_invite_group`;
DELETE FROM `user_invite` WHERE 1;
DELETE FROM `user` WHERE `id` > 1;
truncate `task_user`;
truncate `task`;
DELETE FROM `space_type` WHERE `id` = 11;
truncate `space_module`;
truncate `space_membership`;
DELETE FROM `profile` WHERE `user_id` > 1;
truncate `poll_answer_user`;
truncate `poll_answer`;
truncate `poll`;
truncate `notification`;
truncate `nir_related`;
truncate `log`;
truncate `linklist_link`;
truncate `linklist_category`;
truncate `file`;
truncate`content`;
DELETE FROM `contentcontainer` WHERE 1;
truncate `company_space`;
truncate `company`;
truncate `cfiles_file`;
truncate `card_content`;
DELETE FROM `card` WHERE 1;
DELETE FROM `space` WHERE 1;
truncate `calendar_entry_participant`;
truncate `calendar_entry`;
truncate `activity`;
