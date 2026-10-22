-- Лише явно створені synthetic ролі. Production grants цим B4 не надаються.
GRANT USAGE ON SCHEMA public TO telmanfit_notice_writer, telmanfit_notice_dispatcher;
GRANT SELECT, INSERT ON auth_security_notice_outbox TO telmanfit_notice_writer;
GRANT SELECT ON auth_security_notice_outbox TO telmanfit_notice_dispatcher;
GRANT EXECUTE ON FUNCTION auth_notice_claim(integer), auth_notice_ack(uuid,uuid,text) TO telmanfit_notice_dispatcher;
