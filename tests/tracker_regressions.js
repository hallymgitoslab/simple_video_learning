"use strict";

// Exercise the real browser tracker with minimal DOM and Rhymix.ajax adapters.
// Run: node tests/tracker_regressions.js
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const handlers = {};
const attributes = {};
const progress = { textContent: "" };
const wrapper = {
    getAttribute: (name) => name === "data-document-srl" ? "10" : null,
    querySelector: () => progress
};
const video = {
    currentTime: 9,
    duration: 3600,
    paused: false,
    ended: false,
    getAttribute: (name) => attributes[name] || null,
    setAttribute: (name, value) => { attributes[name] = value; },
    closest: () => wrapper,
    addEventListener: (name, handler) => { handlers[name] = handler; }
};
const shell = {
    getAttribute: (name) => name === "data-simple_video_learning-module-srl" ? "1" : "10",
    querySelectorAll: () => [video]
};
let response = { duration_known: false, position: 0, frontier: 0, percentage: 0, completed: false };
const window = {
    Rhymix: {},
    addEventListener: () => {},
    setInterval: () => 1,
    clearInterval: () => {}
};
const Rhymix = {
    ajax: (action, payload, callback) => {
        assert.equal(action, "simple_video_learning.procSimple_video_learningProgress");
        callback(response);
    }
};
const context = {
    window, Rhymix,
    document: { readyState: "complete", querySelector: () => shell },
    location: { search: "", pathname: "/learning" },
    URLSearchParams
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../public/tracker.js"), "utf8"), context);

handlers.loadedmetadata();
handlers.pause();
assert.equal(video.currentTime, 9, "Unknown-duration playback must not rewind after a heartbeat");
video.currentTime = 20;
handlers.seeking();
assert.equal(video.currentTime, 20, "Unknown-duration seeking must not use an unverified frontier");
assert.match(progress.textContent, /진도가 기록되지 않습니다/);
console.log("PASS unknown-duration videos remain playable without claiming verified progress");

response = { duration_known: true, position: 5, frontier: 5, percentage: 0.14, completed: false };
handlers.pause();
assert.equal(video.currentTime, 5, "Known-duration playback must honor the verified position");
video.currentTime = 20;
handlers.seeking();
assert.equal(video.currentTime, 5, "Known-duration seeking must honor the verified frontier");
assert.match(progress.textContent, /0\.1%/);
console.log("PASS known-duration videos retain position and seek restrictions");
