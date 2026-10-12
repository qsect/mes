<template>
  <div class="dashboard-container">
    <!-- 1. 顶部渐变横幅：问候语 + 实时时钟 + 济南天气 -->
    <div class="welcome-banner">
      <div class="banner-content">
        <div class="greeting-box">
          <h2>欢迎回来，{{ userDisplayName }}！</h2>
          <p>今天是 {{ todayStr }}，请查看最新公告。</p>
        </div>

        <div class="banner-widgets">
          <!-- 实时时钟 -->
          <div class="clock-box">
            <div class="clock-time">{{ clockTime }}</div>
            <div class="clock-label">
              <el-icon style="vertical-align: middle; margin-right: 4px;"><Clock /></el-icon>
              北京时间
            </div>
          </div>

          <!-- 分隔线 -->
          <div class="banner-divider"></div>

          <!-- 天气面板 -->
          <div class="weather-box">
            <div v-if="weatherLoading" class="weather-loading">
              <el-icon class="is-loading mr-1"><Loading /></el-icon> 天气加载中...
            </div>
            <div v-else-if="weatherError" class="weather-loading">
              <el-icon class="mr-1"><Cloudy /></el-icon> 天气获取失败
            </div>
            <div v-else>
              <div class="weather-main">
                <span class="weather-icon">{{ weatherCurrent.iconEmoji }}</span>
                <div class="weather-text">
                  <div class="weather-temp">{{ weatherCurrent.temp }}°C</div>
                  <div class="weather-desc">济南 · {{ weatherCurrent.text }}</div>
                  <div class="weather-sub">
                    湿度 {{ weatherCurrent.humidity }}% · 风速 {{ weatherCurrent.wind }} km/h
                  </div>
                </div>
              </div>
              <div class="weather-forecast">
                <div v-for="(f, idx) in weatherDaily" :key="idx" class="forecast-item">
                  <div class="forecast-day">{{ f.day }}</div>
                  <div class="forecast-icon">{{ f.iconEmoji }}</div>
                  <div class="forecast-temp">{{ f.min }}~{{ f.max }}°</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. 最新公告列表卡片 -->
    <el-card shadow="never" class="notice-card mt-3">
      <template #header>
        <div class="notice-header">
          <div class="notice-header-title">
            <el-icon style="color: #409EFF; margin-right: 6px;"><Bell /></el-icon>
            <span>系统最新公告 Top 10</span>
          </div>
          <span v-if="unreadCount > 0" class="unread-badge">
            {{ unreadCount }} 条未读
          </span>
        </div>
      </template>

      <div v-loading="noticeLoading">
        <table class="notice-table">
          <thead>
            <tr>
              <th>公告标题</th>
              <th style="width: 140px; text-align: center;">发布人</th>
              <th style="width: 190px;">发布时间</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in noticeList"
              :key="item.id"
              class="home-notice-row"
              :class="{ 'notice-unread': isUnread(item.id) }"
              @click="handleNoticeClick(item)"
            >
              <td>
                <span v-if="item.is_pinned" class="badge-pin">置顶</span>
                <el-icon class="notice-item-icon"><Document /></el-icon>
                <strong class="notice-item-title">{{ item.title }}</strong>
                <!-- 列表第一条为最新公告小红旗 -->
                <span v-if="index === 0" class="notice-flag" title="最新公告">🚩</span>
                <!-- 未读小红点 -->
                <span v-if="isUnread(item.id)" class="notice-unread-dot" title="未读"></span>
              </td>
              <td style="text-align: center; color: #64748b;">
                {{ item.author_name || '用户' }}
              </td>
              <td style="color: #94a3b8; font-size: 13px;">
                {{ formatTime(item.created_at) }}
              </td>
            </tr>
            <tr v-if="noticeList.length === 0 && !noticeLoading">
              <td colspan="3" class="notice-empty">
                <el-empty description="暂无公告" :image-size="70" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </el-card>

    <!-- 3. 公告快速弹窗详情 -->
    <el-dialog
      v-model="noticeModalVisible"
      :title="modalTopic.title || '公告详情'"
      width="720px"
      destroy-on-close
    >
      <div v-loading="modalLoading" style="min-height: 120px;">
        <div class="modal-notice-meta">
          <span>发布者：<strong>{{ modalTopic.author_name || '-' }}</strong></span>
          <span>时间：{{ modalTopic.created_at || '-' }}</span>
          <span>浏览：{{ modalTopic.view_count ?? 0 }}</span>
        </div>
        <div class="modal-notice-content" v-safe-html="modalTopic.content"></div>
      </div>
      <template #footer>
        <el-button @click="noticeModalVisible = false">关闭</el-button>
        <el-button type="primary" @click="goToForum">前往讨论区</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { Clock, Bell, Document, Loading, Cloudy } from '@element-plus/icons-vue';
import request from '@/api/request';

const router = useRouter();
const authStore = useAuthStore();

// ---------------- 用户信息与日期 ----------------
const userDisplayName = computed(() => {
  return authStore.user?.full_name || authStore.user?.username || '用户';
});

const todayStr = computed(() => {
  return new Date().toLocaleDateString('zh-CN', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
});

// ---------------- 实时走秒时钟 ----------------
const clockTime = ref('--:--:--');
let clockTimer = null;

const updateClock = () => {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  clockTime.value = `${hh}:${mm}:${ss}`;
};

// ---------------- 天气获取 (济南坐标 Open-Meteo) ----------------
const LAT = 36.65;
const LON = 117.12;

const weatherLoading = ref(true);
const weatherError = ref(false);
const weatherCurrent = ref({ temp: 0, text: '晴', humidity: 0, wind: 0, iconEmoji: '☀️' });
const weatherDaily = ref([]);

const WMO_EMOJI_MAP = {
  0: { text: '晴', emoji: '☀️' },
  1: { text: '基本晴朗', emoji: '🌤️' },
  2: { text: '局部多云', emoji: '⛅' },
  3: { text: '阴', emoji: '☁️' },
  45: { text: '雾', emoji: '🌫️' },
  48: { text: '冻雾', emoji: '🌫️' },
  51: { text: '小毛毛雨', emoji: '🌦️' },
  53: { text: '毛毛雨', emoji: '🌧️' },
  55: { text: '大毛毛雨', emoji: '🌧️' },
  61: { text: '小雨', emoji: '🌧️' },
  63: { text: '中雨', emoji: '🌧️' },
  65: { text: '大雨', emoji: '⛈️' },
  71: { text: '小雪', emoji: '🌨️' },
  73: { text: '中雪', emoji: '❄️' },
  75: { text: '大雪', emoji: '❄️' },
  77: { text: '雪粒', emoji: '🌨️' },
  80: { text: '阵雨', emoji: '🌦️' },
  81: { text: '强阵雨', emoji: '⛈️' },
  82: { text: '暴雨', emoji: '⛈' },
  85: { text: '阵雪', emoji: '🌨️' },
  86: { text: '强阵雪', emoji: '❄️' },
  95: { text: '雷阵雨', emoji: '🌩️' },
  96: { text: '雷雨伴冰雹', emoji: '⛈️' },
  99: { text: '强雷暴伴冰雹', emoji: '⛈️' }
};

const getWmoEmoji = (code) => {
  return WMO_EMOJI_MAP[code] || { text: '晴朗', emoji: '🌤️' };
};

const getDayName = (isoStr) => {
  const today = new Date();
  const diff = Math.round((new Date(isoStr).setHours(0,0,0,0) - today.setHours(0,0,0,0)) / 86400000);
  if (diff === 0) return '今天';
  if (diff === 1) return '明天';
  if (diff === 2) return '后天';
  return ['周日', '周一', '周二', '周三', '周四', '周五', '周六'][new Date(isoStr).getDay()];
};

const fetchWeather = async () => {
  weatherLoading.value = true;
  weatherError.value = false;
  try {
    const url = `https://api.open-meteo.com/v1/forecast?latitude=${LAT}&longitude=${LON}`
      + `&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m`
      + `&daily=weather_code,temperature_2m_max,temperature_2m_min`
      + `&timezone=Asia%2FShanghai&forecast_days=3`;
    const res = await fetch(url);
    if (!res.ok) throw new Error('网络请求异常');
    const data = await res.json();

    const cur = data.current;
    const info = getWmoEmoji(cur.weather_code);
    weatherCurrent.value = {
      temp: Math.round(cur.temperature_2m),
      text: info.text,
      humidity: cur.relative_humidity_2m,
      wind: Math.round(cur.wind_speed_10m),
      iconEmoji: info.emoji
    };

    const daily = data.daily;
    weatherDaily.value = daily.time.map((t, i) => {
      const dInfo = getWmoEmoji(daily.weather_code[i]);
      return {
        day: getDayName(t),
        iconEmoji: dInfo.emoji,
        min: Math.round(daily.temperature_2m_min[i]),
        max: Math.round(daily.temperature_2m_max[i])
      };
    });
  } catch (err) {
    weatherError.value = true;
  } finally {
    weatherLoading.value = false;
  }
};

// ---------------- 公告与已读记录 (用户隔离缓存) ----------------
const readSet = ref(new Set());
const noticeList = ref([]);
const noticeLoading = ref(false);

const getReadStorageKey = () => {
  const uid = authStore.user?.id || authStore.user?.username || 'anon';
  return `mes_read_notices_${uid}`;
};

const loadReadSet = async () => {
  // 1) 先用本地缓存快速渲染
  try {
    const raw = localStorage.getItem(getReadStorageKey());
    const arr = raw ? JSON.parse(raw) : [];
    readSet.value = new Set(Array.isArray(arr) ? arr.map(String) : []);
  } catch (e) {
    readSet.value = new Set();
  }
  // 2) 再从服务端拉取已读列表（换浏览器/电脑也保持一致）
  try {
    const res = await request.get('/discuss/list_notice_reads');
    const ids = res.data?.topic_ids || [];
    readSet.value = new Set(ids.map(String));
    saveReadSet();
  } catch (e) {}
};

// 标记公告已读（服务端持久化：一次查看即永久已读）
const markNoticeReadOnServer = async (topicId) => {
  try {
    await request.post('/discuss/mark_notice_read', { topic_id: topicId });
  } catch (e) {}
};

const saveReadSet = () => {
  try {
    localStorage.setItem(getReadStorageKey(), JSON.stringify(Array.from(readSet.value).slice(-1000)));
  } catch (e) {}
};

const isUnread = (topicId) => {
  return !readSet.value.has(String(topicId));
};

const unreadCount = computed(() => {
  return noticeList.value.filter(n => isUnread(n.id)).length;
});

// 加载公告数据
const fetchNotices = async () => {
  noticeLoading.value = true;
  try {
    // 公开接口：所有人可见，不依赖讨论区"查看"权限
    const res = await request.get('/discuss/list_notices');
    noticeList.value = res.data?.topics || [];
  } catch (err) {
    noticeList.value = [];
  } finally {
    noticeLoading.value = false;
  }
};

// ---------------- 公告弹窗详情 ----------------
const noticeModalVisible = ref(false);
const modalLoading = ref(false);
const modalTopic = ref({});

const handleNoticeClick = async (item) => {
  const sid = String(item.id);
  if (!readSet.value.has(sid)) {
    readSet.value.add(sid);
    saveReadSet();
    // 持久化到服务端，保证换浏览器/电脑后仍是已读
    markNoticeReadOnServer(item.id);
  }

  modalTopic.value = { ...item };
  noticeModalVisible.value = true;
  modalLoading.value = true;

  try {
    const res = await request.get('/discuss/get_notice_detail', { params: { id: item.id } });
    if (res.data?.topic) {
      modalTopic.value = res.data.topic;
    }
  } catch (e) {
  } finally {
    modalLoading.value = false;
  }
};

const goToForum = () => {
  noticeModalVisible.value = false;
  router.push('/discuz');
};

const formatTime = (timeStr) => {
  return String(timeStr || '').substring(0, 16);
};

onMounted(() => {
  loadReadSet();
  updateClock();
  clockTimer = setInterval(updateClock, 1000);
  fetchWeather();
  fetchNotices();
});

onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer);
});
</script>

<style scoped>
.dashboard-container {
  display: flex;
  flex-direction: column;
}

/* 顶部蓝色渐变横幅 */
.welcome-banner {
  background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
  color: #ffffff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(34, 74, 190, 0.15);
}
.banner-content {
  padding: 24px 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 20px;
}
.greeting-box h2 {
  margin: 0 0 8px 0;
  font-size: 22px;
  font-weight: 700;
}
.greeting-box p {
  margin: 0;
  font-size: 14px;
  opacity: 0.85;
}

/* 小组件区：时钟 + 天气 */
.banner-widgets {
  display: flex;
  align-items: center;
  gap: 28px;
  flex-wrap: wrap;
}
.clock-box {
  text-align: center;
  min-width: 150px;
}
.clock-time {
  font-size: 32px;
  font-weight: 700;
  font-family: 'Courier New', Courier, monospace;
  letter-spacing: 2px;
  line-height: 1.1;
}
.clock-label {
  font-size: 12px;
  opacity: 0.85;
  margin-top: 4px;
}
.banner-divider {
  width: 1px;
  height: 58px;
  background: rgba(255, 255, 255, 0.28);
}

.weather-box {
  min-width: 220px;
  text-align: center;
}
.weather-loading {
  font-size: 13px;
  opacity: 0.85;
  display: flex;
  align-items: center;
  justify-content: center;
}
.weather-main {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
.weather-icon {
  font-size: 36px;
  line-height: 1;
}
.weather-text {
  text-align: left;
}
.weather-temp {
  font-size: 26px;
  font-weight: 700;
  line-height: 1;
}
.weather-desc {
  font-size: 12px;
  opacity: 0.9;
  margin-top: 2px;
}
.weather-sub {
  font-size: 11px;
  opacity: 0.75;
  margin-top: 2px;
}
.weather-forecast {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-top: 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.25);
  padding-top: 8px;
}
.forecast-item {
  text-align: center;
  font-size: 11px;
  opacity: 0.9;
}
.forecast-icon {
  font-size: 14px;
  margin: 2px 0;
}

/* 公告卡片 */
.notice-card {
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.notice-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.notice-header-title {
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  color: #1e293b;
}
.unread-badge {
  background: #e74a3b;
  color: #ffffff;
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 12px;
  line-height: 1.5;
  font-weight: 600;
}

/* 公告表格 */
.notice-table {
  width: 100%;
  border-collapse: collapse;
}
.notice-table th {
  padding: 10px 14px;
  background-color: #f8fafc;
  color: #64748b;
  font-size: 13px;
  font-weight: 600;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
}
.notice-table td {
  padding: 14px 14px;
  border-bottom: 1px solid #f1f5f9;
  font-size: 14px;
  vertical-align: middle;
}

.home-notice-row {
  cursor: pointer;
  transition: background-color 0.2s;
}
.home-notice-row:hover {
  background-color: #f1f7ff !important;
}

/* 呼吸式背景高亮 */
@keyframes homeNoticePulse {
  0%, 100% { background-color: #fffaf0; }
  50%      { background-color: #ffe7a8; }
}
.home-notice-row.notice-unread {
  animation: homeNoticePulse 1.8s ease-in-out infinite;
}
.home-notice-row.notice-unread td:first-child {
  box-shadow: inset 3px 0 0 0 #f6c23e;
}

/* 置顶标签 */
.badge-pin {
  background: #f6c23e;
  color: #fff;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 3px;
  margin-right: 6px;
  font-weight: bold;
}

.notice-item-icon {
  color: #409EFF;
  margin-right: 8px;
  vertical-align: middle;
}
.notice-item-title {
  color: #2c3e50;
  vertical-align: middle;
}

/* 最新小红旗摇摆动效 */
@keyframes homeNoticeFlagWave {
  0%, 100% { transform: rotate(-8deg); }
  50%      { transform: rotate(8deg); }
}
.notice-flag {
  display: inline-block;
  margin-left: 6px;
  font-size: 13px;
  transform-origin: 0% 100%;
  animation: homeNoticeFlagWave 1.6s ease-in-out infinite;
  vertical-align: middle;
}

/* 未读小红点扩散动画 */
@keyframes homeNoticeDotPing {
  0%   { box-shadow: 0 0 0 0 rgba(231,74,59,.55); }
  70%  { box-shadow: 0 0 0 7px rgba(231,74,59,0); }
  100% { box-shadow: 0 0 0 0 rgba(231,74,59,0); }
}
.notice-unread-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #e74a3b;
  margin-left: 8px;
  vertical-align: middle;
  animation: homeNoticeDotPing 1.6s ease-out infinite;
}

.notice-empty {
  text-align: center;
  padding: 40px 0;
}

/* 弹窗样式 */
.modal-notice-meta {
  font-size: 12px;
  color: #94a3b8;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 10px;
  margin-bottom: 16px;
  display: flex;
  gap: 16px;
}
.modal-notice-content {
  font-size: 14.5px;
  color: #334155;
  line-height: 1.8;
  min-height: 100px;
  word-break: break-all;
}
.modal-notice-content :deep(img) {
  max-width: 100%;
  border-radius: 4px;
  margin: 8px 0;
}

.mt-3 {
  margin-top: 14px;
}
.mr-1 {
  margin-right: 4px;
}
</style>