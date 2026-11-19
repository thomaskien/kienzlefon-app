#!/usr/bin/env python3
"""Check contract 1.0.2 fixtures, not a running implementation.

Install requirements.txt; RFC-3339 validation must never be silently skipped.
"""
import json
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker

HERE = Path(__file__).resolve().parent


def require(condition, message):
    if not condition:
        raise ValueError(message)


def check_semantics(kind, body):
    if kind in {"Overview", "ControlState"}:
        routing = body["routing_control"]
        group_available = {group["id"]: group["available"] for group in routing["groups"]}
        require(set(group_available) == {"normal", "specialist"}, "group_ids")
        require(len({e["id"] for e in body["extensions"]}) == len(body["extensions"]), "extension_ids")
        current_epochs = []
        if routing["available"]:
            require(routing["revision"].split(":")[1] == routing["pbx_epoch"], "revision_epoch_mismatch")
            current_epochs.append(routing["pbx_epoch"])
        if body["live_counters"]["status"] == "current":
            current_epochs.append(body["live_counters"]["pbx_epoch"])
        require(len(set(current_epochs)) <= 1, "epoch_mismatch")
        if kind == "ControlState" and current_epochs:
            require(body["pbx_epoch"] is not None, "epoch_unknown")
            require(all(epoch == body["pbx_epoch"] for epoch in current_epochs), "epoch_mismatch")
        for extension in body["extensions"]:
            for group, available in group_available.items():
                permission = extension["routing"]["permissions"][group]
                writable = permission["can_enable"] or permission["can_disable"]
                if not routing["available"]:
                    require(extension["routing"][group] is None, "unavailable_membership")
                    require(not writable, "unavailable_permissions")
                if not routing["enabled"]:
                    require(not writable, "disabled_permissions")
                if extension["routing"][group] is None:
                    require(not writable, "unknown_membership_permissions")
                if not available:
                    require(not writable, "unavailable_group_permissions")
                if permission["disable_reason"] == "last_member":
                    require(extension["routing"][group] is True, "last_member_inactive")
                    require(sum(e["routing"][group] is True for e in body["extensions"]) == 1, "last_member_count")
        if kind == "Overview":
            today = body["call_statistics"]["today"]
            if today["status"] != "unavailable":
                ids = [r["id"] for r in today["routes"]]
                require(len(set(ids)) == len(ids) == 11, "statistics_routes")
                require(today["unclassified_calls"] <= today["incoming_calls"], "statistics_unclassified")
                require(all(r["incoming_calls"] <= today["incoming_calls"] for r in today["routes"]), "statistics_counts")
    if kind == "RoutingCommand":
        require(body["expected_revision"].split(":")[1] == body["pbx_epoch"], "revision_epoch_mismatch")
    if kind == "CommandResponse" and body["request"]:
        job = body["request"]
        require(job["expected_revision"].split(":")[1] == job["pbx_epoch"], "revision_epoch_mismatch")
        if job["state"] == "applied":
            require(job["result_revision"].split(":")[1] == job["pbx_epoch"], "revision_epoch_mismatch")
        require(body["error"] == job["error"], "request_error_mismatch")
    if kind == "RoutingPolicy":
        require(len({e["id"] for e in body["extensions"]}) == len(body["extensions"]), "extension_ids")
        for extension in body["extensions"]:
            if extension["specialist"]["eligible"]:
                require(extension["specialist"]["penalty"] == 0, "specialist_penalty")


def check_http_cases(scenario, fixtures):
    status_by_error = {
        "upstream_unreachable": 503,
        "upstream_authentication_failed": 503,
        "state_unavailable": 503,
        "upstream_timeout": 504,
    }
    for case in scenario["cases"]:
        fixture = fixtures[case["response_fixture"]]
        require(fixture["valid"] and not fixture.get("semantic_error"), "http_invalid_fixture")
        body, kind = fixture["body"], fixture["schema"]
        label = case["id"]
        if case["endpoint"] == "/api/v1/telephony/overview":
            require(case["method"] == "GET" and kind == "Overview", label)
            require(case["expected_http"] == 200, label)
            baseline = fixtures["overview-current"]["body"]
            for part in ("routing_control", "live_counters"):
                if part in case["unavailable_parts"]:
                    require(not body[part]["available"] if part == "routing_control"
                            else body[part]["status"] == "unavailable", label)
                else:
                    require(body[part] == baseline[part], label)
            require(body["call_statistics"] == baseline["call_statistics"], label)
            state_fields = ("id", "state", "state_as_of", "state_age_ms")
            require([[e[k] for k in state_fields] for e in body["extensions"]]
                    == [[e[k] for k in state_fields] for e in baseline["extensions"]], label)
        elif case["endpoint"] == "/telepraxis-telephony.php?action=status":
            require(case["method"] == "GET", label)
            if kind == "Overview":
                require(case["expected_http"] == 200, label)
            else:
                require(kind == "ErrorResponse", label)
                require(case["expected_http"] == status_by_error[body["error"]["code"]], label)
        else:
            require(case["endpoint"] in {
                "/api/v1/extension-routing",
                "/api/v1/extension-routing/requests/{request_id}",
            }, label)
            require(kind == "CommandResponse" and body["request"]["state"] == "unknown", label)
            method = "POST" if case["endpoint"] == "/api/v1/extension-routing" else "GET"
            require(case["method"] == method, label)
            require(case["expected_http"] == (202 if method == "POST" else 200), label)


def main():
    checker = FormatChecker()
    for name, valid, invalid in [
        ("date-time", "2026-11-18T10:00:00+01:00", "not-a-date+01:00"),
        ("date", "2026-11-18", "2026-02-30"),
    ]:
        require(name in checker.checkers, "Missing format checker; install requirements.txt: " + name)
        require(checker.conforms(valid, name) and not checker.conforms(invalid, name), "Inactive format checker: " + name)
    schema = json.loads((HERE / "schema.json").read_text())
    Draft202012Validator.check_schema(schema)
    fixture_data = json.loads((HERE / "fixtures.json").read_text())
    fixtures = fixture_data["fixtures"]
    by_name = {}
    for fixture in fixtures:
        name = fixture["name"]
        require(name not in by_name, "Duplicate fixture: " + name)
        by_name[name] = fixture
        selected = {"$schema": schema["$schema"], "$defs": schema["$defs"],
                    "$ref": "#/$defs/" + fixture["schema"]}
        validator = Draft202012Validator(selected, format_checker=checker)
        errors = list(validator.iter_errors(fixture["body"]))
        require((not errors) == fixture["valid"], (name, [e.message for e in errors]))
        if errors:
            require("semantic_error" not in fixture, "Semantic fixture must be schema-valid: " + name)
            continue
        actual_error = None
        try:
            check_semantics(fixture["schema"], fixture["body"])
        except ValueError as error:
            actual_error = str(error)
        require(actual_error == fixture.get("semantic_error"), (name, actual_error, fixture.get("semantic_error")))

    scenario_data = json.loads((HERE / "scenarios.json").read_text())
    require(fixture_data["contract_version"] == scenario_data["contract_version"] == "1.0.2", "Contract version mismatch")
    scenarios = scenario_data["scenarios"]
    require(len({s["id"] for s in scenarios}) == len(scenarios), "Duplicate scenario")
    for scenario in scenarios:
        if scenario["id"] == "live-path":
            for step in scenario["steps"]:
                require(len(step["expected"]) == 3 and sum(step["expected"]) <= 1, "Live path counters")
        if scenario["id"] == "stale-cache":
            require(sum(scenario[k] for k in ("source_age_ms", "proxy_delay_ms", "browser_elapsed_ms")) >= 10000, "Stale cache")
        if scenario["id"] == "http-fault-isolation":
            check_http_cases(scenario, by_name)
    print(f"Contract schema OK; {len(fixtures)} fixtures and {len(scenarios)} scenario descriptions checked.")
    print("No implementation or live integration tested.")


if __name__ == "__main__":
    main()
