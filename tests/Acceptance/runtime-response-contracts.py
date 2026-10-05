#!/usr/bin/env python3
"""Independent real-HTTP acceptance against the unchanged OpenAPI 3.1 contract.

Run from the backend root, through runtime-response-contracts.sh. The wrapper
owns an isolated migrated database, guarded synthetic fixture, HTTP server and
server restart between `write` and `read`. Dependencies: jsonschema[format] == 4.26.0.
No application classes, mocked requests, source rewrites or schema coercion.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from decimal import Decimal

from jsonschema import Draft202012Validator, FormatChecker

HOME = "/api/v1/homes/{homeId}"
LISTS = HOME + "/shopping-lists"
LIST = LISTS + "/{listId}"
LINES = LIST + "/lines"
RECEIPTS = HOME + "/receipts"
RECEIPT = RECEIPTS + "/{receiptId}"
RECEIPT_LINES = RECEIPT + "/lines"
COMMIT = RECEIPT + "/commit"
MOVEMENTS = HOME + "/stock-movements"
ADJUST = HOME + "/stock-adjustments"
PROFILE = "/api/v1/me/profile"
ONBOARDING = "/api/v1/me/onboarding"
GROUPS = "/api/v1/admin/access/groups"
ACCESS = "/api/v1/admin/access/{scope}/{subjectId}"
ME = "/api/v1/me"
BOOTSTRAP = HOME + "/sync/bootstrap"
PULL = HOME + "/sync/pull"
TINY = "0.00000001"
EXPECTED_CASES = {
    ("POST", LISTS), ("POST", LINES), ("GET", LIST), ("GET", LISTS),
    ("POST", RECEIPTS), ("POST", RECEIPT_LINES), ("GET", RECEIPT),
    ("POST", COMMIT), ("GET", MOVEMENTS), ("GET", PROFILE),
    ("POST", ONBOARDING), ("PATCH", PROFILE), ("POST", GROUPS),
    ("GET", ACCESS), ("GET", ME), ("POST", ADJUST), ("GET", RECEIPTS),
}


def ensure(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def pointer(value: str) -> str:
    return value.replace("~", "~0").replace("/", "~1")


def private_json(path: Path) -> dict:
    mode = path.stat()
    ensure(stat.S_ISREG(mode.st_mode) and stat.S_IMODE(mode.st_mode) == 0o600,
           "Synthetic fixture/state must be a regular mode-0600 file")
    ensure(mode.st_uid == os.getuid(), "Synthetic fixture/state must be owned by this user")
    return json.loads(path.read_text())


def save_private(path: Path, value: dict) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as stream:
        os.fchmod(stream.fileno(), 0o600)
        json.dump(value, stream, indent=2)


class Probe:
    def __init__(self, phase: str):
        self.phase = phase
        self.contract_path = Path(os.environ.get(
            "PROVIDENTIA_RESPONSE_CONTRACT", "contracts/openapi/providentia-v1.json"))
        raw = self.contract_path.read_bytes()
        self.contract_hash = hashlib.sha256(raw).hexdigest()
        self.contract = json.loads(raw)
        ensure(self.contract["openapi"].startswith("3.1."), "OpenAPI 3.1 required")
        self.format_checker = FormatChecker()
        required_formats = {"date", "date-time", "email", "uri", "uuid"}
        ensure(required_formats <= set(self.format_checker.checkers),
               "Missing required format checkers; install jsonschema[format]==4.26.0")
        self.failures: list[str] = []
        self.validated = 0
        self.http_count = 0
        self.cases: set[tuple[str, str]] = set()
        if phase == "self-test":
            return
        ensure(os.environ.get("APP_ENV") == "test" and
               os.environ.get("PROVIDENTIA_STEP2_CONFORMANCE") == "1",
               "Explicit isolated test environment required")
        self.fixture_path = Path(os.environ["PROVIDENTIA_RESPONSE_FIXTURE"])
        self.fixture = private_json(self.fixture_path)
        self.state_path = Path(str(self.fixture_path) + ".state.json")
        self.base = os.environ["PROVIDENTIA_RESPONSE_URL"].rstrip("/")
        url = urllib.parse.urlsplit(self.base)
        ensure(url.scheme == "http" and url.hostname in {"127.0.0.1", "::1"} and
               not url.username and not url.password and not url.path and
               not url.query and not url.fragment, "Only isolated loopback HTTP is supported")
        # Ignore ambient proxies: bearer tokens must only reach the local fixture server.
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        self.home = self.fixture["homeId"]
        self.params = {"homeId": self.home}

    def validator(self, ref: str) -> Draft202012Validator:
        # Keep local references rooted in the original document; no dereferencing
        # rewrite, nullable adaptation, format suppression, or number-to-text cast.
        return Draft202012Validator({**self.contract, "$ref": ref},
                                    format_checker=self.format_checker)

    def check_schema(self, value, ref: str, label: str) -> None:
        errors = sorted(self.validator(ref).iter_errors(value), key=lambda e: str(e.path))
        self.validated += 1
        for error in errors:
            location = "/".join(map(str, error.absolute_path)) or "<root>"
            # No request headers, fixture contents or bearer tokens are printed.
            self.failures.append(f"{label}: {location}: {error.message}")

    def component(self, value, name: str, label: str) -> None:
        self.check_schema(value, f"#/components/schemas/{name}", label)

    def request(self, method: str, template: str, body=None, *, params=None,
                actor="alice", expected=200, query=None, headers=None):
        operation = self.contract["paths"][template][method.lower()]
        if body is not None:
            ref = (f"#/paths/{pointer(template)}/{method.lower()}/requestBody/"
                   "content/application~1json/schema")
            errors = list(self.validator(ref).iter_errors(body))
            ensure(not errors, f"Probe request violates {method} {template} schema: "
                   + "; ".join(e.message for e in errors))
        route = template.format(**{**self.params, **(params or {})})
        if query:
            route += "?" + urllib.parse.urlencode(query)
        outgoing = {"Accept": "application/json", "Content-Type": "application/json"}
        if actor is not None:
            outgoing["Authorization"] = "Bearer " + self.fixture["sessions"][actor]["accessToken"]
        outgoing.update(headers or {})
        req = urllib.request.Request(self.base + route,
                                     data=None if body is None else json.dumps(body).encode(),
                                     headers=outgoing, method=method)
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status = response.status
            content_type = response.headers.get("Content-Type", "").split(";")[0]
            raw = response.read()
        self.http_count += 1
        statuses = expected if isinstance(expected, tuple) else (expected,)
        ensure(status in statuses,
               f"{method} {template}: expected HTTP {statuses}, received {status}")
        if status == 204:
            ensure(not raw, f"{method} {template}: HTTP 204 must not contain a body")
            return None
        try:
            value = json.loads(raw)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise AssertionError(f"{method} {template}: response is not JSON") from error
        if 200 <= status < 300:
            ensure(content_type == "application/json", f"{method} {template}: wrong content type")
            ensure(str(status) in operation["responses"],
                   f"{method} {template}: success status is not declared")
            ref = (f"#/paths/{pointer(template)}/{method.lower()}/responses/{status}/"
                   "content/application~1json/schema")
            self.check_schema(value, ref, f"{method} {template} HTTP {status}")
            self.cases.add((method, template))
        else:
            ensure(content_type in {"application/problem+json", "application/json"},
                   f"{method} {template}: error must be structured JSON")
            ensure(isinstance(value, dict), "Authorization rejection must be an object")
        return value

    def decimal(self, value, expected: str, label: str) -> None:
        ensure(isinstance(value, str), f"{label}: decimal must remain a JSON string")
        ensure("e" not in value.lower(), f"{label}: exponent notation is not allowed")
        ensure(Decimal(value) == Decimal(expected), f"{label}: decimal value changed")

    def maps(self, access: dict, *, empty=False) -> None:
        self.component(access, "EffectiveAccess", "access map")
        for key in ("features", "limits", "rolePermissions"):
            ensure(isinstance(access[key], dict), f"{key} must remain an object")
            if empty:
                ensure(access[key] == {}, f"{key}: empty group unexpectedly populated")
        ensure(isinstance(access["delegablePermissions"], list),
               "delegablePermissions must remain an array")

    def profile_maps(self, profile: dict, *, empty_account=False, empty_admin=False) -> None:
        self.maps(profile["accountAccess"], empty=empty_account)
        self.maps(profile["administratorAccess"], empty=empty_admin)

    def sync(self, template: str, cursor=None) -> tuple[list[dict], str]:
        records = []
        seen = set()
        for _ in range(100):
            query = {"limit": 3}
            if cursor:
                query["cursor"] = cursor
            response = self.request("GET", template, query=query)
            records.extend(response["records" if template == BOOTSTRAP else "changes"])
            if not response["hasMore"]:
                return records, response["snapshotCursor" if template == BOOTSTRAP else "pageCursor"]
            cursor = response["pageCursor"]
            ensure(cursor and cursor not in seen, "Sync pagination failed to advance")
            seen.add(cursor)
        raise AssertionError("Synthetic sync did not terminate within 100 pages")

    def sync_representations(self, rows: list[dict], state: dict, *, committed: bool,
                             label: str) -> None:
        # OpenAPI intentionally leaves sync representations generic. Validate the
        # envelope above, then canonical field schemas plus factual values here.
        schemas = {"shopping-list": "ShoppingList", "shopping-list-line": "ShoppingListLine",
                   "purchasing-receipt": "Receipt", "purchasing-receipt-line": "ReceiptLine"}
        expected_ids = {"shopping-list": state["listId"],
                        "shopping-list-line": state["shoppingLineId"],
                        "purchasing-receipt": state["receiptId"],
                        "purchasing-receipt-line": state["receiptLineId"]}
        latest = {}
        for row in rows:
            kind = row.get("entityType")
            if kind not in schemas or row.get("entityId") != expected_ids[kind]:
                continue
            ensure(row.get("operation") != "delete", f"{label}: unexpected deletion")
            rep = row["representation"]
            ensure(rep["id"] == row["entityId"] and rep["revision"] == row["revision"],
                   f"{label}: envelope and representation disagree")
            for field, value in rep.items():
                if field in self.contract["components"]["schemas"][schemas[kind]]["properties"]:
                    self.check_schema(value, f"#/components/schemas/{schemas[kind]}/properties/{field}",
                                      f"{label} {kind}.{field}")
            if kind == "shopping-list-line":
                self.decimal(rep["quantityToBuy"], TINY, label + " shopping quantity")
            elif kind == "purchasing-receipt-line":
                self.decimal(rep["quantity"], TINY, label + " receipt quantity")
                self.decimal(rep["unitPrice"], "25.5", label + " unit price")
                self.decimal(rep["lineTotal"], "0.01", label + " line total")
            elif kind == "purchasing-receipt":
                self.decimal(rep["totalAmount"], "0.01", label + " total amount")
            latest[kind] = rep
        ensure(set(latest) == set(schemas), f"{label}: missing shopping/receipt representations")
        ensure(latest["purchasing-receipt-line"]["approvalStatus"] == "approved",
               f"{label}: line approval not represented")
        ensure(latest["purchasing-receipt"]["status"] == ("committed" if committed else "draft"),
               f"{label}: incorrect receipt status")
        ensure(latest["shopping-list-line"]["checked"] is True,
               f"{label}: checked shopping line not represented")

    def unauthorized(self, state: dict) -> None:
        other = {**state, "homeId": self.fixture["otherHomeId"]}
        for route in (LISTS, LIST, RECEIPTS, RECEIPT, MOVEMENTS, BOOTSTRAP, PULL):
            self.request("GET", route, params=other, expected=(403, 404))
        self.request("POST", COMMIT, {"expectedRevision": state["commitExpectedRevision"]},
                     params=other, expected=(403, 404))
        self.request("POST", ADJUST,
                     {"homeProductId": state["productId"], "quantityDelta": TINY,
                      "reason": "Unauthorized fixture isolation check"},
                     params=other, headers={"Idempotency-Key": str(uuid.uuid4())}, expected=(403, 404))
        self.request("GET", RECEIPT, params=state, actor=None, expected=401)
        self.request("GET", ACCESS, params={"scope": "home", "subjectId": self.home},
                     actor="bob", expected=403)

    def receipt_values(self, receipt: dict, state: dict) -> None:
        ensure(receipt["id"] == state["receiptId"] and receipt["homeId"] == self.home,
               "Receipt identity/home changed")
        self.decimal(receipt["totalAmount"], "0.01", "receipt total")
        line = next(x for x in receipt["lines"] if x["id"] == state["receiptLineId"])
        ensure(line["receiptId"] == receipt["id"], "Receipt line relationship changed")
        ensure(line["homeProductId"] == state["productId"], "Approved receipt product changed")
        ensure(receipt["currency"] == "NAD" and receipt["purchaseDate"] == "2026-10-03",
               "Receipt currency or purchase date changed")
        self.decimal(line["quantity"], TINY, "receipt line quantity")
        self.decimal(line["unitPrice"], "25.5", "receipt line unit price")
        self.decimal(line["lineTotal"], "0.01", "receipt line total")

    def exactly_once(self, state: dict) -> list[str]:
        movements = self.request("GET", MOVEMENTS,
                                 query={"homeProductId": state["productId"], "limit": 100})["data"]
        purchases = [x for x in movements if x["sourceType"] == "receipt-line" and
                     x["sourceId"] == state["receiptLineId"]]
        ensure(len(purchases) == 1, "Receipt commit must create exactly one purchase movement")
        self.decimal(purchases[0]["quantityDelta"], TINY, "purchase movement")
        ensure(len(movements) == 3, "Expected two adjustments and one purchase, without duplicates")
        ensure(sum(Decimal(x["quantityDelta"]) for x in movements) == Decimal(TINY),
               "Authoritative movement sum must equal the single receipt purchase")
        return sorted(x["id"] for x in movements)

    def write(self) -> None:
        bob = self.request("GET", PROFILE, actor="bob")
        original = bob["accountAccess"]
        empty = self.request("POST", GROUPS, {
            "scope": "account", "name": "Synthetic empty response-contract group",
            "features": {}, "limits": {}, "delegablePermissions": [],
            "rolePermissions": {}, "expectedRevision": 0})
        for key in ("features", "limits", "rolePermissions"):
            ensure(empty[key] == {}, f"Created empty group {key} must be an object")
        full_input = {"scope": "home", "name": "Synthetic populated response-contract group",
                      "features": {"home.read": True, "shopping.write": False},
                      "limits": {"products.total": 7}, "delegablePermissions": ["home.read"],
                      "rolePermissions": {"viewer": ["home.read"]}, "expectedRevision": 0}
        full = self.request("POST", GROUPS, full_input)
        for key in ("features", "limits", "delegablePermissions", "rolePermissions"):
            ensure(full[key] == full_input[key], f"Populated group {key} changed")
        subject = {"scope": "account", "subjectId": bob["userId"]}
        self.request("PUT", ACCESS, {"groupId": empty["id"], "expectedRevision": original["revision"]},
                     params=subject)
        effective = self.request("GET", ACCESS, params=subject)
        self.maps(effective, empty=True)
        bob = self.request("GET", PROFILE, actor="bob")
        self.profile_maps(bob, empty_account=True, empty_admin=True)
        policy = self.fixture["policy"]
        bob = self.request("POST", ONBOARDING, {
            "displayName": "Synthetic response-contract participant", "countryCode": "NA",
            "locale": "en", "timezone": "UTC", "expectedRevision": bob["revision"],
            "policyAccepted": True, "policyId": policy["id"], "policyRevision": int(policy["revision"])},
            actor="bob")
        self.profile_maps(bob, empty_account=True, empty_admin=True)
        bob = self.request("PATCH", PROFILE, {
            "displayName": "Synthetic response-contract participant updated", "countryCode": "NA",
            "locale": "en", "timezone": "UTC", "expectedRevision": bob["revision"]}, actor="bob")
        self.profile_maps(bob, empty_account=True, empty_admin=True)
        me = self.request("GET", ME, actor="bob")
        self.profile_maps(me["profile"], empty_account=True, empty_admin=True)
        self.request("PUT", ACCESS, {"groupId": original["groupId"],
                     "expectedRevision": effective["revision"]}, params=subject)
        restored = self.request("GET", PROFILE, actor="bob")
        self.profile_maps(restored, empty_admin=True)
        ensure(restored["accountAccess"]["features"] and restored["accountAccess"]["limits"],
               "Restored account maps must be populated")
        alice = self.request("GET", PROFILE)
        self.profile_maps(alice)
        ensure(alice["administratorAccess"]["features"], "Administrator features must be populated")
        self.profile_maps(self.request("GET", ME)["profile"])
        home_access = self.request("GET", ACCESS, params={"scope": "home", "subjectId": self.home})
        self.maps(home_access)
        ensure(home_access["features"] and home_access["limits"] and home_access["rolePermissions"],
               "Default home maps must be populated")

        empty_snapshot, initial_cursor = self.sync(BOOTSTRAP)
        ensure(not any(x["entityType"] in {"shopping-list", "purchasing-receipt"}
                       for x in empty_snapshot), "Fixture unexpectedly contains business resources")
        product = self.request("POST", HOME + "/products",
                               {"privateName": "Synthetic tiny-decimal acceptance product", "unit": "g"},
                               expected=201)
        state = {"productId": product["id"], "initialCursor": initial_cursor,
                 "contractSha256": self.contract_hash}
        for quantity in (TINY, "-" + TINY):
            key = str(uuid.uuid4())
            payload = {"homeProductId": product["id"], "quantityDelta": quantity,
                       "reason": "Synthetic tiny decimal response-contract adjustment"}
            adjustment = self.request("POST", ADJUST, payload, expected=201,
                                      headers={"Idempotency-Key": key})
            self.decimal(adjustment["quantityDelta"], quantity, "adjustment quantity")
            replay = self.request("POST", ADJUST, payload, expected=201,
                                  headers={"Idempotency-Key": key})
            ensure(replay["id"] == adjustment["id"], "Adjustment replay created another movement")
        shopping = self.request("POST", LISTS, {"name": "Synthetic response-contract list", "kind": "manual"},
                                expected=201)
        state["listId"] = shopping["id"]
        line = self.request("POST", LINES, {
            "expectedListRevision": shopping["revision"], "homeProductId": product["id"],
            "description": "Synthetic tiny shopping quantity", "quantityToBuy": TINY},
            params=state, expected=201)
        state["shoppingLineId"] = line["id"]
        self.decimal(line["quantityToBuy"], TINY, "created shopping quantity")
        ensure(line["checked"] is False, "New shopping line must be unchecked")
        self.request("PUT", LINES + "/{lineId}/checked",
                     {"expectedRevision": line["revision"], "checked": True},
                     params={**state, "lineId": line["id"]}, expected=204)
        shopping = self.request("GET", LIST, params=state)
        ensure(len(shopping["lines"]) == 1 and shopping["lines"][0]["checked"] is True,
               "Checked shopping line must be readable")
        self.decimal(shopping["lines"][0]["quantityToBuy"], TINY, "shopping readback")
        ensure(any(x["id"] == state["listId"] for x in self.request("GET", LISTS)["data"]),
               "Created shopping list must appear in list readback")

        receipt = self.request("POST", RECEIPTS, {
            "purchaseDate": "2026-10-03", "currency": "NAD", "totalAmount": "0.01",
            "notes": "Synthetic response-contract receipt"}, expected=201)
        state["receiptId"] = receipt["id"]
        line = self.request("POST", RECEIPT_LINES, {
            "expectedReceiptRevision": receipt["revision"], "rawDescription": "Synthetic tiny purchase",
            "quantity": TINY, "unitPrice": "25.50", "lineTotal": "0.01"}, params=state, expected=201)
        state["receiptLineId"] = line["id"]
        self.decimal(line["quantity"], TINY, "created receipt quantity")
        self.request("POST", RECEIPT_LINES + "/{lineId}/approve", {
            "homeProductId": product["id"], "expectedRevision": line["revision"]},
            params={**state, "lineId": line["id"]}, expected=204)
        approved = self.request("GET", RECEIPT, params=state)
        self.receipt_values(approved, state)
        ensure(approved["lines"][0]["approvalStatus"] == "approved", "Receipt line not approved")
        before_commit, _ = self.sync(BOOTSTRAP)
        self.sync_representations(before_commit, state, committed=False, label="approved bootstrap")
        state["commitExpectedRevision"] = approved["revision"]
        committed = self.request("POST", COMMIT, {"expectedRevision": approved["revision"]}, params=state)
        self.receipt_values(committed, state)
        ensure(committed["status"] == "committed", "Receipt commit did not return committed resource")
        state["committedRevision"] = committed["revision"]
        replay = self.request("POST", COMMIT, {"expectedRevision": approved["revision"]}, params=state)
        self.receipt_values(replay, state)
        ensure(replay["status"] == "committed" and replay["revision"] == committed["revision"],
               "Repeated commit must return the same committed revision")
        history = self.request("GET", RECEIPTS)["data"]
        ensure(len([x for x in history if x["id"] == state["receiptId"]]) == 1,
               "Receipt must appear exactly once in purchase history")
        self.decimal(next(x for x in history if x["id"] == state["receiptId"])["totalAmount"],
                     "0.01", "purchase history total")
        state["movementIds"] = self.exactly_once(state)
        snapshot, state["finalCursor"] = self.sync(BOOTSTRAP)
        self.sync_representations(snapshot, state, committed=True, label="committed bootstrap")
        changes, state["pullCursor"] = self.sync(PULL, initial_cursor)
        self.sync_representations(changes, state, committed=True, label="incremental pull")
        self.unauthorized(state)
        ensure(self.exactly_once(state) == state["movementIds"], "Rejected requests altered inventory")
        ensure(EXPECTED_CASES <= self.cases, "Missing one or more of the 17 original/sibling endpoint cases")
        save_private(self.state_path, state)

    def read(self) -> None:
        state = private_json(self.state_path)
        ensure(state["contractSha256"] == self.contract_hash, "Contract changed between restart phases")
        receipt = self.request("GET", RECEIPT, params=state)
        self.receipt_values(receipt, state)
        replay = self.request("POST", COMMIT, {"expectedRevision": state["commitExpectedRevision"]}, params=state)
        self.receipt_values(replay, state)
        ensure(replay["status"] == "committed" and replay["revision"] == state["committedRevision"],
               "Post-restart repeated commit changed authoritative receipt state")
        ensure(self.exactly_once(state) == state["movementIds"],
               "Post-restart repeated commit changed movement identities")
        shopping = self.request("GET", LIST, params=state)
        ensure(shopping["lines"][0]["checked"] is True, "Shopping checked state lost after restart")
        self.decimal(shopping["lines"][0]["quantityToBuy"], TINY, "post-restart shopping quantity")
        self.request("GET", LISTS)
        self.request("GET", RECEIPTS)
        snapshot, _ = self.sync(BOOTSTRAP)
        self.sync_representations(snapshot, state, committed=True, label="post-restart bootstrap")
        changes, _ = self.sync(PULL, state["initialCursor"])
        self.sync_representations(changes, state, committed=True, label="post-restart historical pull")
        empty, _ = self.sync(PULL, state["pullCursor"])
        ensure(not empty, "Replay unexpectedly emitted another synchronization effect")
        self.unauthorized(state)
        self.profile_maps(self.request("GET", PROFILE, actor="bob"), empty_admin=True)

    def boundary(self) -> None:
        """Separate diagnostic: identify database-affinity precision loss explicitly."""
        maximum = "999999999.99999999"
        shopping = self.request("POST", LISTS, {
            "name": "Synthetic maximum-decimal diagnostic", "kind": "manual"}, expected=201)
        line = self.request("POST", LINES, {
            "expectedListRevision": shopping["revision"],
            "description": "Declared maximum quantity precision diagnostic",
            "quantityToBuy": maximum}, params={"listId": shopping["id"]}, expected=201)
        print("BOUNDARY DIAGNOSTIC: requested quantity=" + maximum +
              "; successful HTTP response quantity=" + json.dumps(line.get("quantityToBuy")))
        readback = self.request("GET", LIST, params={"listId": shopping["id"]})
        actual = readback["lines"][0]["quantityToBuy"]
        print("BOUNDARY DIAGNOSTIC: persisted readback quantity=" + json.dumps(actual))
        self.decimal(actual, maximum, "Declared maximum decimal precision")

    def self_test(self) -> None:
        tests = [
            ("ShoppingListLine", {"id": str(uuid.uuid4()), "description": "x",
                                  "quantityToBuy": TINY, "checked": False, "revision": 1},
             [("quantityToBuy", 0.00000001), ("quantityToBuy", "1e-8"),
              ("checked", 0), ("revision", "1"), ("id", "bad-uuid")]),
            ("StockMovement", {"id": str(uuid.uuid4()), "homeId": str(uuid.uuid4()),
                               "homeProductId": str(uuid.uuid4()), "movementType": "adjustment",
                               "quantityDelta": TINY, "sourceType": "adjustment", "sourceId": "fixture",
                               "occurredAt": "2026-10-03T00:00:00Z"},
             [("quantityDelta", 1e-8), ("occurredAt", "2026-10-03 00:00:00")]),
            ("EffectiveAccess", {"scope": "account", "subjectId": str(uuid.uuid4()),
                                 "groupId": None, "features": {}, "limits": {}, "revision": 0,
                                 "groupRevision": 0, "delegablePermissions": [], "rolePermissions": {}},
             [("features", []), ("limits", []), ("rolePermissions", [])]),
        ]
        mutations = 0
        for name, valid, invalid in tests:
            validator = self.validator(f"#/components/schemas/{name}")
            ensure(validator.is_valid(valid), f"Positive validator control failed: {name}")
            for field, value in invalid:
                altered = copy.deepcopy(valid)
                altered[field] = value
                ensure(not validator.is_valid(altered), f"Validator missed {name}.{field} regression")
                mutations += 1
        for name in ("Receipt", "ShoppingList", "ReceiptLine"):
            ensure(not self.validator(f"#/components/schemas/{name}").is_valid(
                {"id": str(uuid.uuid4()), "revision": 1}), f"Validator accepted incomplete {name}")
            mutations += 1
        print(f"Validator positive controls and {mutations} deliberate regression controls passed.")

    def finish(self) -> None:
        ensure(hashlib.sha256(self.contract_path.read_bytes()).hexdigest() == self.contract_hash,
               "Canonical contract changed during acceptance")
        ensure(not self.failures, f"{len(self.failures)} actual response schema violations")
        print(f"PASS {self.phase}: {self.http_count} real HTTP requests; "
              f"{self.validated} schema validations; {len(self.cases & EXPECTED_CASES)}/17 "
              "original/sibling endpoint cases in this phase; no contract changes.")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise AssertionError("Redirects are forbidden for the local bearer-token acceptance probe")


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in {"write", "read", "self-test", "boundary"}:
        print("Usage: runtime-response-contracts.py {self-test|write|read|boundary}", file=sys.stderr)
        return 2
    probe = Probe(sys.argv[1])
    try:
        if probe.phase == "self-test":
            probe.self_test()
        else:
            getattr(probe, probe.phase)()
            probe.finish()
    except (AssertionError, KeyError, StopIteration, urllib.error.URLError) as error:
        for failure in probe.failures:
            print("SCHEMA FAILURE: " + failure, file=sys.stderr)
        print(f"FAIL {probe.phase}: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
