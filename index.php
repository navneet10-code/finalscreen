<?php
<html>
<script src="https://cdn.webrtc-experiment.com/RecordRTC.js"> </script>
        <script src="https://cdn.webrtc-experiment.com/getScreenId.js"></script>
        <script src="https://webrtc.github.io/adapter/adapter-latest.js"></script>
<section class="experiment">
                <h2 class="header">Record audio/screen and convert/merge into "mp4"!</h2>

                <div class="inner">
                    <button id="record-video">Record</button>
                    <button id="stop-recording-video" disabled>Stop</button>
                    <br>
                    <video id="video-preview" controls muted></video>
                    <br>
                </div>
            </section>
            <script>
                var recordVideo, recordAudio;
                var videoPreview = document.getElementById('video-preview');
                var inner = document.querySelector('.inner');
                var videoFile = !!navigator.mozGetUserMedia ? 'video.gif' : 'video.webm';
                var isFirefox = !!navigator.mozGetUserMedia;
                function captureScreenAndAudio(callback) {
                    getScreenId(function(error, sourceId, screenConstraints) {
                        if (error === 'not-installed') {
                            document.write('<h1><a target="_blank" href="https://chrome.google.com/webstore/detail/screen-capturing/ajhifddimkapgcifgcodmmfdlknahffk">Please install this chrome extension then reload the page.</a></h1>');
                        }
                        if (error === 'permission-denied') {
                            alert('Screen capturing permission is denied.');
                        }
                        if (error === 'installed-disabled') {
                            alert('Please enable chrome screen capturing extension.');
                        }
                        if (error) {
                            config.onMediaCapturingFailed(error);
                            return;
                        }
                        // first getUserMedia for screen
                        // note: Firefox supports both audio+screen capturing in single getUserMedia
                        navigator.getUserMedia(screenConstraints, function(screenStream) {
                            // second getUserMedia for audio
                            navigator.getUserMedia({
                                audio: true
                            }, function(audioStream) {
                                callback(screenStream, audioStream);
                            }, function(error) {
                                alert(JSON.stringify(error, null, '\t'));
                            });
                        }, function(error) {
                            alert(JSON.stringify(error, null, '\t'));
                        });
                    });
                }
                document.querySelector('#record-video').onclick = function() {
                    this.disabled = true;
                    captureScreenAndAudio(function(screenStream, audioStream) {
                        screenStream.onended = function() {
                            document.querySelector('#stop-recording-video').onclick();
                        };
                        videoPreview.style.position = 'absolute';
                        videoPreview.style.top = 0;
                        videoPreview.style.left = 0;
                        videoPreview.style['object-fill'] = 'fill';
                        videoPreview.style.width = '10%';
                        videoPreview.style.height = '10%';
                        videoPreview.style.zIndex = 9999999999999;
                            videoPreview.srcObject = screenStream;
                            videoPreview.play();
                            recordAudio = RecordRTC(audioStream, {
                                type: isFirefox ? 'video' : 'audio',
                                // bufferSize: 16384,
                                recorderType: isFirefox ? MediaStreamRecorder : StereoAudioRecorder // force WebAudio for all browsers even for Firefox/MS-Edge
                            });
                            recordAudio.stream = audioStream;
                            // todo: Firefox supports MediaRecorder API
                            // however it will record both audio/video tracks into single WebM
                            // Need to construct a MediaStream that is having no audio tracks
                            recordVideo = RecordRTC(screenStream, {
                                type: 'video',
                                recorderType: isFirefox ? MediaStreamRecorder : WhammyRecorder,
                                canvas: {
                                    width: 360,
                                    height: 240
                                },
                                video: videoPreview,
                                frameInterval: 90 // 30 milliseconds
                            });
                            recordVideo.stream = screenStream;
                            recordVideo.initRecorder(function() {
                                recordAudio.initRecorder(function() {
                                    recordVideo.startRecording();
                                    recordAudio.startRecording();
                                });
                            });
                    });
                    document.querySelector('#stop-recording-video').disabled = false;
                };
                document.querySelector('#stop-recording-video').onclick = function() {
                    if(!recordVideo.stream) return;
                    this.disabled = true;
                    recordAudio.stopRecording(function() {
                        recordVideo.stopRecording(function() {
                            recordVideo.stream.onended = function() {};
                            recordVideo.stream.stop();
                            recordAudio.stream.stop();
                            recordVideo.stream = null;
                            recordAudio.stream = null;
                            videoPreview.style.position = '';
                            videoPreview.style.top = 'auto';
                            videoPreview.style.left = 'auto';
                            videoPreview.style['object-fill'] = 'initial';
                            videoPreview.style.width = 360;
                            videoPreview.style.height = 240;
                            videoPreview.style.zIndex = 9999999999999;
                            convertStreams(recordVideo.blob, recordAudio.blob);
                            log('<a href="'+ workerPath +'" download="ffmpeg-asm.js">ffmpeg-asm.js</a> file download started. It is about 18MB in size; please be patient!');
                        });
                    });
                };
                var workerPath = 'https://archive.org/download/ffmpeg_asm/ffmpeg_asm.js';
				if(document.domain == 'localhost') {
					workerPath = location.href.replace(location.href.split('/').pop(), '') + 'ffmpeg_asm.js';
				}
                function processInWebWorker() {
                    var blob = URL.createObjectURL(new Blob(['importScripts("' + workerPath + '");var now = Date.now;function print(text) {postMessage({"type" : "stdout","data" : text});};onmessage = function(event) {var message = event.data;if (message.type === "command") {var Module = {print: print,printErr: print,files: message.files || [],arguments: message.arguments || [],TOTAL_MEMORY: message.TOTAL_MEMORY || false};postMessage({"type" : "start","data" : Module.arguments.join(" ")});postMessage({"type" : "stdout","data" : "Received command: " +Module.arguments.join(" ") +((Module.TOTAL_MEMORY) ? ".  Processing with " + Module.TOTAL_MEMORY + " bits." : "")});var time = now();var result = ffmpeg_run(Module);var totalTime = now() - time;postMessage({"type" : "stdout","data" : "Finished processing (took " + totalTime + "ms)"});postMessage({"type" : "done","data" : result,"time" : totalTime});}};postMessage({"type" : "ready"});'], {
                        type: 'application/javascript'
                    }));
                    var worker = new Worker(blob);
                    URL.revokeObjectURL(blob);
                    return worker;
                }
                var worker;
                function convertStreams(videoBlob, audioBlob) {
                    var vab;
                    var aab;
                    var buffersReady;
                    var workerReady;
                    var posted = false;
                    var fileReader1 = new FileReader();
                    fileReader1.onload = function() {
                        vab = this.result;
                        if (aab) buffersReady = true;
                        if (buffersReady && workerReady && !posted) postMessage();
                    };
                    var fileReader2 = new FileReader();
                    fileReader2.onload = function() {
                        aab = this.result;
                        if (vab) buffersReady = true;
                        if (buffersReady && workerReady && !posted) postMessage();
                    };
                    fileReader1.readAsArrayBuffer(videoBlob);
                    fileReader2.readAsArrayBuffer(audioBlob);
                    if (!worker) {
                        worker = processInWebWorker();
                    }
                    worker.onmessage = function(event) {
                        var message = event.data;
                        if (message.type == "ready") {
                            log('<a href="'+ workerPath +'" download="ffmpeg-asm.js">ffmpeg-asm.js</a> file has been loaded.');
                            workerReady = true;
                            if (buffersReady)
                                postMessage();
                        } else if (message.type == "stdout") {
                            log(message.data);
                        } else if (message.type == "start") {
                            log('<a href="'+ workerPath +'" download="ffmpeg-asm.js">ffmpeg-asm.js</a> file received ffmpeg command.');
                        } else if (message.type == "done") {
                            log(JSON.stringify(message));
                            var result = message.data[0];
                            log(JSON.stringify(result));
                            var blob = new Blob([result.data], {
                                type: 'video/mp4'
                            });
                            log(JSON.stringify(blob));
                            PostBlob(blob);
                        }
                    };
                    var postMessage = function() {
                        posted = true;
                        worker.postMessage({
                            type: 'command',
                            arguments: [
                                '-i', videoFile,
                                '-i', 'audio.wav',
                                '-c:v', 'mpeg4',
                                '-c:a', 'vorbis',
                                '-b:v', '48k',
                                '-b:a', '8k',
                                '-strict', 'experimental', 'output.mp4'
                            ],
                            files: [
                                {
                                    data: new Uint8Array(vab),
                                    name: videoFile
                                },
                                {
                                    data: new Uint8Array(aab),
                                    name: "audio.wav"
                                }
                            ]
                        });
                    };
                }
                function PostBlob(blob) {
                    var video = document.createElement('video');
                    video.controls = true;
                    var source = document.createElement('source');
                    source.src = URL.createObjectURL(blob);
                    source.type = 'video/mp4; codecs=mpeg4';
                    video.appendChild(source);
                    video.download = 'Play mp4 in VLC Player.mp4';
                    inner.appendChild(document.createElement('hr'));
                    var h2 = document.createElement('h2');
                    h2.innerHTML = '<a href="' + source.src + '" target="_blank" download="Play mp4 in VLC Player.mp4">Download Converted mp4 and play in VLC player!</a>';
                    inner.appendChild(h2);
                    h2.style.display = 'block';
                    inner.appendChild(video);
                    video.tabIndex = 0;
                    video.focus();
                    video.play();
                    document.querySelector('#record-video').disabled = false;
                }
                var logsPreview = document.getElementById('logs-preview');
                function log(message) {
                    var li = document.createElement('li');
                    li.innerHTML = message;
                    logsPreview.appendChild(li);
                    li.tabIndex = 0;
                    li.focus();
                }
                window.onbeforeunload = function() {
                    document.querySelector('#record-video').disabled = false;
                };
            </script>
			</html>
?>
