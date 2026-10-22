DROP TRIGGER auth6b_flow_gate ON auth_flows;
DROP TRIGGER auth6b_session_gate ON auth_session_states;
DROP FUNCTION auth6b_lock_case(uuid);
DROP TABLE auth_recovery_events, auth_recovery_gates, auth_recovery_grants, auth_recovery_approvals, auth_recovery_cases, auth_recovery_keys, auth_recovery_principals;
DROP FUNCTION auth6b_immutable(), auth6b_registry_guard(), auth6b_case_guard(), auth6b_check_approvals(uuid), auth6b_approval_guard(), auth6b_grant_guard(), auth6b_gate_guard(), auth6b_accept_approval(uuid), auth6b_quarantine(uuid,uuid,text), auth6b_exchange(uuid,text,text,uuid), auth6b_complete(uuid), auth6b_close(uuid,text), auth6b_auth_gate_guard();
