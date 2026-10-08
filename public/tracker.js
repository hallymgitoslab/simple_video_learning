(function () {
    "use strict";

    function init() {
        if (window.console && console.debug) {
            console.debug("[simple_video_learning] progress tracker loaded");
        }
        var shell = document.querySelector(".simple_video_learning-shell[data-simple_video_learning-module-srl]");
        if (!shell) return;

        var players = shell.querySelectorAll(".simple_video_learning-tracked-video, .simple_video_learning-lecture-content video");
        if (!players.length) {
            if (window.console && console.warn) console.warn("[simple_video_learning] progress tracker: no video element found");
            return;
        }

        players.forEach(function (video) {
            if (video.getAttribute("data-simple_video_learning-tracker-bound") === "Y") return;
            video.setAttribute("data-simple_video_learning-tracker-bound", "Y");

            var wrapper = video.closest(".simple_video_learning-lecture") || video.closest(".simple_video_learning-lecture-content") || shell;
            var documentSrl = wrapper ? parseInt(wrapper.getAttribute("data-document-srl") || "0", 10) : 0;
            if (!documentSrl) {
                documentSrl = parseInt(shell.getAttribute("data-simple_video_learning-document-srl") || "0", 10);
            }
            var accessModuleSrl = parseInt(shell.getAttribute("data-simple_video_learning-module-srl") || "0", 10);
            if (!documentSrl) {
                documentSrl = parseInt(new URLSearchParams(location.search).get("document_srl") || "0", 10);
            }
            if (!documentSrl) {
                var match = location.pathname.match(/\/(\d+)(?:\/)?$/);
                if (match) documentSrl = parseInt(match[1], 10);
            }
            if (!documentSrl) return;

            var frontier = 0;
            var lastSafe = 0;
            var progressEl = wrapper.querySelector(".simple_video_learning-progress");
            var heartbeatTimer = null;
            var restoring = false;
            var durationKnown = true;

            function render(data) {
                if (!data) return;
                durationKnown = data.duration_known !== false;
                frontier = Math.max(frontier, Number(data.frontier || 0));
                lastSafe = Number(data.position || lastSafe || 0);
                if (progressEl) {
                    if (!durationKnown) {
                        progressEl.textContent = "영상 길이 확인 전에는 진도가 기록되지 않습니다.";
                        return;
                    }
                    var pct = Number(data.percentage || 0).toFixed(1);
                    progressEl.textContent = "Verified watch progress: " + pct + "%" + (data.completed ? " ✓" : "");
                }
            }

            function send(playing) {
                if (!window.Rhymix || typeof Rhymix.ajax !== "function") {
                    if (window.console && console.error) {
                        console.error("[simple_video_learning] progress tracker: Rhymix.ajax is unavailable");
                    }
                    return;
                }
                if (window.console && console.debug) {
                    console.debug("[simple_video_learning] progress heartbeat", {
                        document_srl: documentSrl,
                        current_time: Math.floor(video.currentTime || 0),
                        playing: !!playing
                    });
                }
                var request = Rhymix.ajax(
                    "simple_video_learning.procSimple_video_learningProgress",
                    {
                        document_srl: documentSrl,
                        access_module_srl: accessModuleSrl,
                        current_time: Math.floor(video.currentTime || 0),
                        duration: Number.isFinite(video.duration) ? Math.floor(video.duration) : 0,
                        playing: playing ? "Y" : "N"
                    },
                    function (data) {
                        render(data);
                        var serverPos = Number(data.position || 0);
                        if (playing && durationKnown && serverPos + 4 < video.currentTime) {
                            restoring = true;
                            video.currentTime = serverPos;
                            restoring = false;
                        }
                    }
                );
                if (request && typeof request.catch === "function") {
                    request.catch(function (error) {
                        if (window.console && console.error) {
                            console.error("[simple_video_learning] progress heartbeat failed", error);
                        }
                    });
                }
            }

            function stopHeartbeat(recordFinalSegment) {
                if (heartbeatTimer) window.clearInterval(heartbeatTimer);
                heartbeatTimer = null;
                if (recordFinalSegment) send(true);
            }

            video.addEventListener("loadedmetadata", function () {
                send(false);
            });

            video.addEventListener("play", function () {
                if (heartbeatTimer) window.clearInterval(heartbeatTimer);
                send(false);
                heartbeatTimer = window.setInterval(function () { send(!video.paused && !video.ended); }, 5000);
            });

            video.addEventListener("pause", function () {
                stopHeartbeat(true);
            });

            video.addEventListener("ended", function () {
                stopHeartbeat(true);
            });

            video.addEventListener("seeking", function () {
                if (restoring || !durationKnown) return;
                var maxAllowed = frontier + 3;
                if (video.currentTime > maxAllowed) {
                    restoring = true;
                    video.currentTime = Math.max(0, frontier);
                    restoring = false;
                }
            });

            window.addEventListener("beforeunload", function () {
                if (!video.paused && !video.ended) send(true);
            });
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
