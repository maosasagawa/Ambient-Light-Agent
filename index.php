<?php

define('API_KEY', ''); //API KEY
define('BASE_URL_CHAT', 'https://aihubmix.com/v1/chat/completions'); //BASE URL
define('BASE_URL_AUDIO', 'https://aihubmix.com/v1/audio/transcriptions');
define('MODEL_ID_CHAT', 'gpt-5-mini'); 
define('MODEL_ID_AUDIO', 'whisper-large-v3'); 
define('DATA_DIR', __DIR__ . '/data/'); 

function handle_api_error($ch, $response) {
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code !== 200) {
        return ['error' => true, 'message' => "API Error ($http_code): $response"];
    }
    return ['error' => false, 'data' => json_decode($response, true)];
}

if (isset($_GET['api']) && $_GET['api'] === 'esp32') { 
    header('Content-Type: application/json'); 
    $latest_file = get_latest_file(DATA_DIR); 
    if ($latest_file) { 
        $data = json_decode(file_get_contents($latest_file), true); 
        $final_colors_rgb = array_map(function($color) { return $color['rgb']; }, $data['final_selection']); 
        echo json_encode($final_colors_rgb); 
    } else { echo json_encode([[0, 170, 255]]); } 
    exit; 
} 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio_blob'])) {
    header('Content-Type: application/json');
    try {
        $audio_file = $_FILES['audio_blob']['tmp_name'];
        $original_name = $_FILES['audio_blob']['name'];
        $cfile = new CURLFile($audio_file, $_FILES['audio_blob']['type'], $original_name);
        $post_fields = [
            'file' => $cfile,
            'model' => MODEL_ID_AUDIO,
            'language' => 'zh', 
            'prompt' => '请准确转录中文内容，忽略背景噪音，用于智能灯光控制指令。',
            'response_format' => 'json',
            'temperature' => 0.2 
        ];
        $ch = curl_init(BASE_URL_AUDIO);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . API_KEY]);
        $response = curl_exec($ch);
        $result = handle_api_error($ch, $response);
        curl_close($ch);
        if ($result['error']) { echo json_encode(['success' => false, 'message' => $result['message']]); } 
        else { $text = $result['data']['text'] ?? ''; echo json_encode(['success' => true, 'text' => trim($text)]); }
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prompt'])) { 
    $user_prompt = trim($_POST['prompt']); 
    if (!empty($user_prompt)) { 
        try { 
            $llm_response = call_llm($user_prompt); 
            $result = process_and_select_colors($llm_response); 
            $save_data = [ 
                'id' => time(), 'prompt' => $user_prompt, 'theme' => $llm_response['theme'], 
                'reason' => $llm_response['reason'], 'all_candidates' => $result['all_candidates'], 
                'final_selection' => $result['final_selection'] 
            ]; 
            if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true); 
            file_put_contents(DATA_DIR . $save_data['id'] . '.json', json_encode($save_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); 
        } catch (Exception $e) {} 
    } 
    header('Location: ' . $_SERVER['PHP_SELF']); 
    exit; 
} 

$history = get_all_history(DATA_DIR); 
$latest_result = !empty($history) ? $history[0] : null; 

function call_llm($user_prompt) { 
    $prompt = get_llm_prompt($user_prompt); 
    $headers = [ 'Content-Type: application/json', 'Authorization: Bearer ' . API_KEY ]; 
    $body = json_encode([ 'model' => MODEL_ID_CHAT, 'messages' => [['role' => 'user', 'content' => $prompt]], 'response_format' => ['type' => 'json_object'] ]); 
    $ch = curl_init(BASE_URL_CHAT); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
    $response = curl_exec($ch); 
    $result = handle_api_error($ch, $response);
    curl_close($ch);
    if ($result['error']) throw new Exception($result['message']);
    $content = $result['data']['choices'][0]['message']['content'] ?? null; 
    if (!$content) throw new Exception("LLM返回内容为空"); 
    $json_content = json_decode($content, true); 
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("JSON解析失败"); 
    return $json_content; 
} 

function get_llm_prompt($user_input) { 
    return <<<PROMPT
# 角色和目标
您是一名富有创造力的灯光设计师。根据用户描述，生成颜色方案。
# 决策层级
1. **安全至上**: 涉及行车安全/健康（困、快）优先生成警示色（红/黄）。
2. **用户意图**: 用户明确指定颜色（如“蓝色”）优先。
3. **推断**: 其次才根据环境推断。
# 输出格式 (JSON Only)
{ 
  "theme": "简短中文概括", "color_count_suggestion": <1-3>, 
  "candidate_colors": [ {"name": "English Name", "rgb": [R, G, B]}, ... ], 
  "reason": "简短中文解释" 
} 
# 配色规则
1. 第一颜色必须最核心。2. 颜色鲜艳。3. 避免极暗色。
# 用户指令
{$user_input} 
PROMPT; 
} 

function process_and_select_colors($llm_response) { 
    $candidates = $llm_response['candidate_colors']; 
    $count = $llm_response['color_count_suggestion'] ?? 3; 
    $all_candidates_with_status = array_map(function($c) { 
        $validation = get_validation_status($c['rgb']); 
        return array_merge($c, ['validation' => $validation]); 
    }, $candidates); 
    $valid_colors = array_filter($all_candidates_with_status, function($c) { return $c['validation']['valid']; }); 
    $final_selection = []; 
    if (!empty($valid_colors)) { 
        $target_count = min($count, count($valid_colors)); 
        $available_colors = array_values($valid_colors); 
        $final_selection[] = array_shift($available_colors); 
        while (count($final_selection) < $target_count && !empty($available_colors)) { 
            $best_candidate = null; $max_min_distance = -1; $best_candidate_index = -1; 
            foreach ($available_colors as $index => $candidate) { 
                $min_distance_to_selection = PHP_INT_MAX; 
                foreach ($final_selection as $selected) { $min_distance_to_selection = min($min_distance_to_selection, color_distance($candidate['rgb'], $selected['rgb'])); } 
                if ($min_distance_to_selection > $max_min_distance) { $max_min_distance = $min_distance_to_selection; $best_candidate = $candidate; $best_candidate_index = $index; } 
            } 
            if ($best_candidate && $max_min_distance > 80) { $final_selection[] = $best_candidate; array_splice($available_colors, $best_candidate_index, 1); } else { break; } 
        } 
    } 
    $final_selection_names = array_map(function($c) { return $c['name']; }, $final_selection); 
    $final_candidates_list = array_map(function($c) use ($final_selection_names) { 
        if (!$c['validation']['valid']) { $c['status'] = 'filtered'; $c['status_reason'] = $c['validation']['reason']; } 
        elseif (in_array($c['name'], $final_selection_names)) { $c['status'] = 'selected'; $c['status_reason'] = null; } 
        else { $c['status'] = 'not-selected'; $c['status_reason'] = '为保证色彩差异性'; } 
        return $c; 
    }, $all_candidates_with_status); 
    return ['final_selection' => $final_selection, 'all_candidates' => $final_candidates_list]; 
} 

function get_validation_status($rgb) { 
    list($r, $g, $b) = $rgb; $max_val = max($r, $g, $b); $min_val = min($r, $g, $b); 
    if ($max_val < 100) return ['valid' => false, 'reason' => '亮度不足']; 
    if ($max_val == 0) return ['valid' => false, 'reason' => '亮度不足']; 
    $saturation = ($max_val - $min_val) / $max_val; 
    if ($saturation < 0.4) return ['valid' => false, 'reason' => "饱和度过低"]; 
    return ['valid' => true, 'reason' => null]; 
} 

function color_distance($rgb1, $rgb2) { return sqrt(pow($rgb1[0] - $rgb2[0], 2) + pow($rgb1[1] - $rgb2[1], 2) + pow($rgb1[2] - $rgb2[2], 2)); } 
function get_all_history($dir) { if (!is_dir($dir)) return []; $files = glob($dir . '*.json'); if (empty($files)) return []; rsort($files); $history = []; foreach ($files as $file) { $history[] = json_decode(file_get_contents($file), true); } return $history; } 
function get_latest_file($dir) { if (!is_dir($dir)) return null; $files = glob($dir . '*.json'); if (empty($files)) return null; rsort($files); return $files[0]; } 
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI 氛围灯 - 语音增强版</title>
    <style>
        :root { 
            --bg-color: #121212; 
            --surface-color: #1e1e1e; 
            --primary-color: #00aaff; 
            --primary-hover: #0088cc;
            --text-color: #e0e0e0; 
            --text-muted: #888; 
            --border-color: #333; 
            --success-color: #00e676; 
            --error-color: #ff5252; 
            --border-radius: 16px; 
            --recording-color: #ff4081;
        } 
        
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; } 
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: var(--bg-color); color: var(--text-color); display: flex; justify-content: center; min-height: 100vh; padding: 1.5rem 1rem; } 
        
        .container { width: 100%; max-width: 800px; display: flex; flex-direction: column; gap: 1.5rem; padding-bottom: 2rem; } 
        
        header { text-align: center; margin-bottom: 0.5rem; } 
        header h1 { font-size: 1.8rem; font-weight: 700; background: linear-gradient(45deg, var(--primary-color), #00ffaa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.5rem; } 
        header p { color: var(--text-muted); font-size: 0.9rem; } 

        /* 输入区域增强 */
        .input-group { 
            position: relative; 
            background: var(--surface-color); 
            border-radius: var(--border-radius); 
            padding: 0.5rem; 
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        .input-group:focus-within { border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(0, 170, 255, 0.2); }
        
        .input-wrapper { display: flex; align-items: center; gap: 0.5rem; }
        
        #prompt-input { 
            flex: 1; 
            background: transparent; 
            border: none; 
            color: var(--text-color); 
            font-size: 1.1rem; 
            padding: 0.8rem 0.5rem; 
            outline: none; 
            min-width: 0; /* 防止Flex子项溢出 */
        } 
        #prompt-input::placeholder { color: #555; }

        /* 按钮通用样式 */
        .btn-icon {
            background: rgba(255,255,255,0.05);
            border: none;
            cursor: pointer;
            width: 52px; /* 增大移动端触摸面积 */
            height: 52px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.1s, background-color 0.2s;
            color: var(--text-color);
            flex-shrink: 0; /* 防止被压缩 */
            touch-action: manipulation; /* 禁用双击缩放 */
        }
        
        /* 移动端触摸反馈 (替代 Hover) */
        .btn-icon:active {
            background: rgba(255,255,255,0.2);
            transform: scale(0.92);
        }

        /* 桌面端 Hover */
        @media (hover: hover) {
            .btn-icon:hover { background: rgba(255,255,255,0.1); }
            #send-btn:hover { background: var(--primary-hover); }
        }

        .btn-icon svg { width: 26px; height: 26px; fill: currentColor; }

        #send-btn { background: var(--primary-color); color: #fff; }

        /* 录音按钮特效 */
        #mic-btn.recording { 
            color: white;
            background-color: var(--recording-color);
            animation: pulse-red 1.5s infinite;
        }
        #mic-btn.processing {
            color: var(--primary-color);
            background: rgba(0, 170, 255, 0.1);
            animation: spin 1s linear infinite;
        }

        /* 录音状态提示条 */
        .recording-indicator {
            height: 0;
            overflow: hidden;
            transition: height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: var(--recording-color);
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0;
        }
        .recording-indicator.active { height: 36px; opacity: 1; margin-bottom: 5px; }
        .wave-bar {
            width: 4px; height: 10px; background: var(--recording-color); border-radius: 2px;
            animation: wave 1s ease-in-out infinite;
        }
        .wave-bar:nth-child(2) { animation-delay: 0.1s; }
        .wave-bar:nth-child(3) { animation-delay: 0.2s; }
        .wave-bar:nth-child(4) { animation-delay: 0.3s; }

        /* 结果区域 */
        .results-area { display: flex; flex-direction: column; gap: 1.5rem; margin-top: 1rem; } 
        .card { background: var(--surface-color); border-radius: var(--border-radius); padding: 1.5rem; border: 1px solid var(--border-color); }
        
        h3 { font-size: 1.1rem; color: var(--primary-color); margin-bottom: 1rem; border-bottom: 1px solid #333; padding-bottom: 0.5rem; }
        
        .swatches { display: flex; flex-wrap: wrap; gap: 10px; }
        .swatch { 
            flex: 1 1 45%; /* 移动端双列 */
            min-width: 120px;
            height: 100px; border-radius: 12px; 
            padding: 10px; display: flex; flex-direction: column; justify-content: flex-end;
            position: relative; overflow: hidden; font-size: 0.8rem; text-shadow: 0 1px 3px rgba(0,0,0,0.8);
            transition: transform 0.2s;
        }
        .swatch:active { transform: scale(0.98); }
        .swatch.selected { border: 2px solid var(--success-color); box-shadow: 0 0 15px rgba(0, 230, 118, 0.4); }
        .swatch.filtered { opacity: 0.4; filter: grayscale(0.8); }
        .status-tag { position: absolute; top: 5px; right: 5px; font-size: 0.7em; background: rgba(0,0,0,0.6); padding: 2px 6px; border-radius: 4px; }

        /* 动画 */
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(255, 64, 129, 0.7); } 70% { box-shadow: 0 0 0 12px rgba(255, 64, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 64, 129, 0); } }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        @keyframes wave { 0%, 100% { height: 8px; } 50% { height: 18px; } }
        
        .light-strip { height: 50px; border-radius: 25px; margin-top: 1rem; transition: background 0.5s; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
        
        /* 历史记录 */
        .history-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .history-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 12px; cursor: pointer; transition: background 0.2s; }
        .history-item:active { background: rgba(255,255,255,0.1); }

        .loader { width: 22px; height: 22px; border: 3px solid #fff; border-bottom-color: transparent; border-radius: 50%; display: none; animation: spin 1s linear infinite; }

        /* 移动端媒体查询微调 */
        @media (max-width: 480px) {
            header h1 { font-size: 1.5rem; }
            .container { padding: 1rem; gap: 1rem; }
            .card { padding: 1rem; }
            .btn-icon { width: 48px; height: 48px; } /* 稍小的屏幕适配 */
            .swatch { flex: 1 1 100%; } /* 超小屏幕单列显示颜色 */
        }
    </style>
</head>
<body>

    <div class="container">
        <header>
            <h1>AI 氛围灯调色师</h1>
            <p>点击麦克风，说出你的场景</p>
        </header>

        <form id="main-form" method="POST" action="">
            <div class="input-group">
                <div class="recording-indicator" id="rec-indicator">
                    <div class="wave-bar"></div><div class="wave-bar"></div><div class="wave-bar"></div><div class="wave-bar"></div>
                    <span>正在聆听... (停顿自动发送)</span>
                </div>
                <div class="input-wrapper">
                    <!-- 麦克风按钮 -->
                    <button type="button" id="mic-btn" class="btn-icon" title="点击开始/停止">
                        <svg viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                    </button>
                    
                    <input type="text" id="prompt-input" name="prompt" placeholder="描述心情或场景..." autocomplete="off" required>
                    
                    <!-- 发送按钮 -->
                    <button type="submit" id="send-btn" class="btn-icon">
                        <svg viewBox="0 0 24 24" id="send-icon"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        <div class="loader" id="submit-loader"></div>
                    </button>
                </div>
            </div>
        </form>

        <div id="results-area" class="results-area" style="display: none;"></div>

        <?php if (!empty($history)): ?>
        <div class="card">
            <h3>最近生成</h3>
            <div class="history-list">
                <?php foreach (array_slice($history, 0, 5) as $item): ?>
                <div class="history-item" onclick='loadHistory(<?= json_encode($item, JSON_HEX_APOS) ?>)'>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%;"><?= htmlspecialchars($item['prompt']) ?></span>
                    <span style="font-size: 0.8em; color: var(--text-muted);">➜</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // ===================================================================
    // 前端逻辑
    // ===================================================================
    
    let mediaRecorder, audioChunks = [], audioContext, analyser, silenceTimer, streamReference;
    let isRecording = false;

    const micBtn = document.getElementById('mic-btn');
    const promptInput = document.getElementById('prompt-input');
    const recIndicator = document.getElementById('rec-indicator');
    const mainForm = document.getElementById('main-form');
    const resultsArea = document.getElementById('results-area');

    document.addEventListener('DOMContentLoaded', () => {
        const latestResult = <?= json_encode($latest_result) ?>;
        if (latestResult) renderResult(latestResult);
    });

    micBtn.addEventListener('click', toggleRecording);
    
    mainForm.addEventListener('submit', function(e) {
        const btn = document.getElementById('send-btn');
        const icon = document.getElementById('send-icon');
        const loader = document.getElementById('submit-loader');
        
        // 移动端体验优化：提交时收起键盘
        promptInput.blur();
        
        if(promptInput.value.trim() === '') {
            e.preventDefault();
            return;
        }

        btn.disabled = true;
        icon.style.display = 'none';
        loader.style.display = 'block';
    });

    async function toggleRecording() {
        if (isRecording) {
            stopRecording();
        } else {
            startRecording();
        }
    }

    async function startRecording() {
        try {
            // 移动端关键优化：开始录音时收起键盘，防止键盘遮挡录音动画
            promptInput.blur();

            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            streamReference = stream;
            
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const source = audioContext.createMediaStreamSource(stream);
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            source.connect(analyser);

            // 优先使用 webm/opus (Android/Chrome)，iOS Safari 会自动 fallback
            const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') 
                             ? 'audio/webm;codecs=opus' : ''; 
            mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : {});
            
            audioChunks = [];
            mediaRecorder.ondataavailable = event => { if(event.data.size > 0) audioChunks.push(event.data); };
            mediaRecorder.onstop = uploadAudio;

            mediaRecorder.start();
            isRecording = true;
            
            micBtn.classList.add('recording');
            recIndicator.classList.add('active');
            promptInput.placeholder = "正在聆听...";
            
            // 清空旧内容，避免误解
            promptInput.value = "";

            detectSilence();

        } catch (err) {
            console.error(err);
            alert("无法启动麦克风。请检查浏览器权限 (iOS请使用Safari)。");
        }
    }

    function stopRecording() {
        if (!isRecording) return;
        isRecording = false;
        mediaRecorder.stop();
        streamReference.getTracks().forEach(track => track.stop());
        if (audioContext && audioContext.state !== 'closed') audioContext.close();

        micBtn.classList.remove('recording');
        micBtn.classList.add('processing'); 
        recIndicator.classList.remove('active');
        promptInput.placeholder = "正在转录...";
    }

    function detectSilence() {
        if (!isRecording) return;
        const dataArray = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(dataArray);
        
        let sum = 0;
        for (let i = 0; i < dataArray.length; i++) sum += dataArray[i];
        const average = sum / dataArray.length;
        
        // 移动端麦克风底噪可能不同，阈值稍微宽容一点
        if (average < 15) {
            if (!silenceTimer) {
                silenceTimer = setTimeout(() => {
                    if (isRecording) stopRecording();
                }, 1500);
            }
        } else {
            if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
        }
        requestAnimationFrame(detectSilence);
    }

    async function uploadAudio() {
        // iOS Safari 默认生成 audio/mp4 或 audio/mpeg，Chrome 是 webm
        // 这里不指定 type，让 Blob 自动探测，后端兼容性强
        const audioBlob = new Blob(audioChunks); 
        const formData = new FormData();
        formData.append('audio_blob', audioBlob, 'recording.blob');

        try {
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();

            micBtn.classList.remove('processing');
            promptInput.placeholder = "描述心情或场景...";

            if (data.success) {
                typeWriterEffect(data.text);
            } else {
                alert("识别失败，请重试");
            }
        } catch (error) {
            micBtn.classList.remove('processing');
            alert("网络错误");
        }
    }

    function typeWriterEffect(text) {
        let i = 0;
        promptInput.value = "";
        const speed = 40; 
        
        function type() {
            if (i < text.length) {
                promptInput.value += text.charAt(i);
                i++;
                setTimeout(type, speed);
            } else {
                setTimeout(() => { mainForm.submit(); }, 600);
            }
        }
        type();
    }

    window.loadHistory = function(data) {
        renderResult(data);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function renderResult(data) {
        if(!data) return;
        const { theme, reason, all_candidates, final_selection } = data;
        
        resultsArea.innerHTML = `
            <div class="card">
                <h3>${theme}</h3>
                <p style="color: var(--text-muted); line-height: 1.6; font-style: italic;">"${reason}"</p>
                <div class="light-strip" id="preview-strip"></div>
                <h4 style="margin-top: 1.5rem; color: var(--text-color); font-size: 0.9rem;">候选色板</h4>
                <div class="swatches" id="swatches-container"></div>
            </div>
        `;

        const swatchesContainer = document.getElementById('swatches-container');
        const previewStrip = document.getElementById('preview-strip');
        
        all_candidates.forEach(c => {
            const el = document.createElement('div');
            el.className = `swatch ${c.status}`;
            el.style.backgroundColor = `rgb(${c.rgb.join(',')})`;
            el.innerHTML = `<div class="name">${c.name}</div>
                ${c.status !== 'selected' ? '' : '<div class="status-tag" style="background:var(--success-color);color:#000">选中</div>'}
            `;
            swatchesContainer.appendChild(el);
        });

        const finalRgbs = final_selection.map(c => `rgb(${c.rgb.join(',')})`);
        if(finalRgbs.length > 0) {
            if(finalRgbs.length === 1) {
                previewStrip.style.background = finalRgbs[0];
                previewStrip.style.boxShadow = `0 0 30px ${finalRgbs[0]}`;
            } else {
                previewStrip.style.background = `linear-gradient(90deg, ${finalRgbs.join(', ')})`;
                previewStrip.style.boxShadow = `0 0 30px ${finalRgbs[Math.floor(finalRgbs.length/2)]}`;
            }
        }
        resultsArea.style.display = 'flex';
    }
    </script>
</body>
</html>
