<template>
  <div class="discuss-container">
    <!-- 顶部状态栏 -->
    <el-card shadow="never" class="header-card">
      <div class="header-row">
        <div class="header-title">
          <el-icon class="mr-2" color="#409EFF"><ChatLineRound /></el-icon>
          <span>综合讨论交流区</span>
        </div>
        <div class="header-actions">
          <el-input
            v-model="searchKw"
            placeholder="搜索讨论帖子..."
            clearable
            style="width: 220px;"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          >
            <template #prefix>
              <el-icon><Search /></el-icon>
            </template>
          </el-input>
          <el-button v-if="perms.can_create_topic" type="primary" :icon="EditPen" @click="openCreateModal">
            发布新帖
          </el-button>
          <el-badge :value="unreadNotifs" :hidden="unreadNotifs === 0">
            <el-button :icon="Bell" @click="openNotifModal">通知</el-button>
          </el-badge>
        </div>
      </div>
    </el-card>

    <!-- 视图 1: 帖子列表 -->
    <div v-if="viewMode === 'list'" class="mt-3">
      <!-- 分类筛选器 -->
      <el-card shadow="never" class="category-filter-card">
        <el-radio-group v-model="selectedCategory" @change="handleCategoryChange">
          <el-radio-button :value="0">全部话题</el-radio-button>
          <el-radio-button
            v-for="cat in categories"
            :key="cat.id"
            :value="cat.id"
          >
            {{ cat.name }}
          </el-radio-button>
        </el-radio-group>
      </el-card>

      <!-- 主题列表 -->
      <el-card shadow="never" class="mt-3 topic-list-card" v-loading="loadingList">
        <div v-if="topics.length === 0" class="empty-wrap">
          <el-empty description="暂无相关话题，快来发布第一个吧！" />
        </div>
        <div
          v-for="item in topics"
          :key="item.id"
          class="topic-item"
          @click="viewTopicDetail(item.id)"
        >
          <!-- 头像 -->
          <div class="avatar-col">
            <el-avatar :size="42" class="custom-avatar">
              {{ (item.author_name || 'U').substring(0, 1).toUpperCase() }}
            </el-avatar>
          </div>
          <!-- 核心内容信息 -->
          <div class="info-col">
            <div class="title-row">
              <el-tag v-if="item.is_pinned" type="warning" size="small" effect="dark" class="mr-1">置顶</el-tag>
              <el-tag v-if="item.is_closed" type="danger" size="small" effect="dark" class="mr-1">已锁定</el-tag>
              <span class="topic-title">{{ item.title }}</span>
            </div>
            <div class="meta-row">
              <el-tag size="small" type="info">{{ item.category_name }}</el-tag>
              <span class="author-label">{{ item.author_name }}</span>
              <span class="date-label">发布于 {{ formatTime(item.created_at) }}</span>
            </div>
          </div>
          <!-- 统计指标 -->
          <div class="stat-col">
            <div class="stat-box">
              <span class="count">{{ item.reply_count }}</span>
              <span class="label">回复</span>
            </div>
            <div class="stat-box">
              <span class="count">{{ item.view_count }}</span>
              <span class="label">浏览</span>
            </div>
          </div>
        </div>

        <!-- 分页栏 -->
        <div class="pagination-wrap">
          <el-pagination
            background
            layout="total, prev, pager, next"
            :total="total"
            :page-size="perPage"
            v-model:current-page="currentPage"
            @current-change="handlePageChange"
          />
        </div>
      </el-card>
    </div>

    <!-- 视图 2: 帖子详情与楼层回复 -->
    <div v-else-if="viewMode === 'detail'" class="mt-3" v-loading="loadingDetail">
      <div class="mb-3">
        <el-button :icon="ArrowLeft" @click="backToList">返回话题列表</el-button>
      </div>

      <!-- 帖子主体卡片 -->
      <el-card shadow="never" class="detail-card">
        <div class="detail-header">
          <div>
            <h2 class="detail-title">
              <el-tag v-if="currentTopic.is_pinned" type="warning" effect="dark" class="mr-1">置顶</el-tag>
              <el-tag v-if="currentTopic.is_closed" type="danger" effect="dark" class="mr-1">已锁定</el-tag>
              {{ currentTopic.title }}
            </h2>
            <div class="meta-row mt-2">
              <span>作者: <strong>{{ currentTopic.author_name }}</strong></span>
              <span>分类: <el-tag size="small">{{ currentTopic.category_name }}</el-tag></span>
              <span>发布时间: {{ currentTopic.created_at }}</span>
              <span>浏览量: {{ currentTopic.view_count }}</span>
            </div>
          </div>

          <!-- 操作菜单 -->
          <div class="detail-actions">
            <el-button v-if="canEditCurrentTopic" size="small" :icon="Edit" @click="openEditModal">编辑</el-button>
            <el-button v-if="isAdmin" size="small" @click="togglePin">
              {{ currentTopic.is_pinned ? '取消置顶' : '置顶' }}
            </el-button>
            <el-button v-if="isAdmin" size="small" @click="toggleClose">
              {{ currentTopic.is_closed ? '解锁回复' : '锁定回复' }}
            </el-button>
            <el-button
              v-if="perms.can_delete_topic"
              size="small"
              type="danger"
              :icon="Delete"
              @click="confirmDeleteTopic"
            >
              删除
            </el-button>
          </div>
        </div>
        <el-divider />
        <!-- 帖子正文 HTML -->
        <div class="topic-content-body" v-safe-html="currentTopic.content"></div>
      </el-card>

      <!-- 回复楼层卡片 -->
      <el-card shadow="never" class="mt-3 reply-card">
        <template #header>
          <div class="reply-header">
            <span><el-icon class="mr-1"><ChatDotRound /></el-icon> 全部回复 ({{ replies.length }})</span>
          </div>
        </template>
        <div v-if="replies.length === 0" class="empty-wrap">
          <el-empty description="暂无回复，快来留下你的观点吧" :image-size="80" />
        </div>
        <div v-for="(reply, index) in replies" :key="reply.id" class="reply-item">
          <el-avatar :size="36" class="custom-avatar mr-3">
            {{ (reply.author_name || 'U').substring(0, 1).toUpperCase() }}
          </el-avatar>
          <div class="reply-content-col">
            <div class="reply-meta">
              <span class="author-name">{{ reply.author_name }}</span>
              <span class="floor-date">#{{ index + 1 }} 楼 · {{ reply.created_at }}</span>
              <div class="action-btns">
                <el-button
                  v-if="perms.can_reply && !currentTopic.is_closed"
                  link type="primary" size="small"
                  @click="quoteReply(reply, index)"
                >引用</el-button>
                <el-button v-if="perms.can_delete_reply" link type="danger" size="small" @click="deleteReply(reply.id)">删除</el-button>
              </div>
            </div>
            <div class="reply-text" v-safe-html="reply.content"></div>
          </div>
        </div>
      </el-card>

      <!-- 快速发表回复 -->
      <el-card shadow="never" class="mt-3 editor-card">
        <template #header>
          <span style="font-weight:600;"><el-icon class="mr-1"><Promotion /></el-icon>发表回复</span>
        </template>
        <div v-if="currentTopic.is_closed" class="closed-tip">
          <el-alert title="该话题已被锁定，暂停回复" type="warning" :closable="false" show-icon />
        </div>
        <div v-else-if="!perms.can_reply" class="closed-tip">
          <el-alert title="您没有回复权限，请联系管理员开通" type="info" :closable="false" show-icon />
        </div>
        <RichEditor
          v-else
          ref="replyEditorRef"
          v-model="replyForm.content"
          placeholder="在此输入回复内容，支持加粗、颜色、字号、列表、引用、代码、图片…"
          :maxlength="2000"
          :min-height="120"
        >
          <template #actions>
            <el-button link size="small" @click="clearReply">清空</el-button>
            <el-button type="primary" :icon="Position" :loading="submittingReply" @click="submitReply">
              提交回复
            </el-button>
          </template>
        </RichEditor>
      </el-card>
    </div>

    <!-- 弹窗 1: 发布/编辑帖子 -->
    <el-dialog
      v-model="modalVisible"
      :title="isEditing ? '编辑帖子' : '发布新帖'"
      width="760px"
      destroy-on-close
    >
      <el-form :model="topicForm" label-width="80px">
        <el-form-item label="标题" required>
          <el-input v-model="topicForm.title" placeholder="请输入帖子标题" />
        </el-form-item>
        <el-form-item label="分类" required>
          <el-select v-model="topicForm.category_id" style="width: 100%;">
            <el-option
              v-for="c in categories"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="内容正文" required>
          <RichEditor
            ref="topicEditorRef"
            v-model="topicForm.content"
            placeholder="在此输入讨论内容，支持加粗、颜色、字号、对齐、列表、引用、代码块、链接、图片…"
            :min-height="170"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="submittingTopic" @click="handleSaveTopic">
          {{ isEditing ? '保存修改' : '立即发布' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 弹窗 2: 站内通知列表 -->
    <el-dialog v-model="notifModalVisible" title="消息通知" width="560px" destroy-on-close>
      <div v-loading="loadingNotifs">
        <div style="text-align: right; margin-bottom: 10px;">
          <el-button size="small" @click="markAllNotifRead">全部设为已读</el-button>
        </div>
        <div v-if="notifList.length === 0" style="text-align:center; padding:25px; color:#909399;">
          暂无未读消息
        </div>
        <div
          v-for="n in notifList"
          :key="n.id"
          class="notif-item"
          :class="{ unread: !n.is_read }"
          @click="clickNotification(n)"
        >
          <div style="font-size: 13.5px; color:#303133;">{{ n.message }}</div>
          <div style="font-size: 11px; color:#909399; margin-top: 4px;">{{ n.created_at }}</div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  ChatLineRound, Search, EditPen, Bell, ArrowLeft,
  Edit, Delete, ChatDotRound, Promotion, Position
} from '@element-plus/icons-vue';
import request from '@/api/request';
import RichEditor from '@/components/RichEditor.vue';
import { isBlankHtml, buildQuoteHtml } from '@/utils/richText';

const viewMode = ref('list'); // 'list' | 'detail'
const categories = ref([]);
const unreadNotifs = ref(0);
const isAdmin = ref(false);
const currentUserId = ref(0);
// ★ 本模块细粒度权限（查看/发帖/回复/编辑自己的帖子/删帖）
const perms = ref({
  can_view: true,
  can_create_topic: true,
  can_reply: true,
  can_edit: true,
  can_delete_topic: false,
  can_delete_reply: false,
  can_close_topic: false,
  can_pin_topic: false
});

const selectedCategory = ref(0);
const searchKw = ref('');
const topics = ref([]);
const total = ref(0);
const perPage = ref(15);
const currentPage = ref(1);
const loadingList = ref(false);

const loadingDetail = ref(false);
const currentTopic = ref({});
const replies = ref([]);
const replyForm = ref({ content: '' });
const submittingReply = ref(false);
// ★ 富文本编辑器实例（发帖弹窗 / 回帖）
const replyEditorRef = ref(null);
const topicEditorRef = ref(null);

const modalVisible = ref(false);
const isEditing = ref(false);
const submittingTopic = ref(false);
const topicForm = ref({
  id: 0,
  category_id: null,
  title: '',
  content: ''
});

const notifModalVisible = ref(false);
const notifList = ref([]);
const loadingNotifs = ref(false);

const canEditCurrentTopic = computed(() => {
  // 需具备编辑权限，且仅能编辑自己发的帖子（管理员不受限）
  const mayEdit = isAdmin.value || perms.value.can_edit;
  return mayEdit && (isAdmin.value || currentTopic.value.user_id === currentUserId.value);
});

const initData = async () => {
  try {
    const res = await request.get('/discuss/init');
    if (res.data) {
      categories.value = res.data.categories || [];
      unreadNotifs.value = res.data.unread_notifications || 0;
      isAdmin.value = res.data.is_admin || false;
      currentUserId.value = res.data.current_user?.id || 0;
      if (res.data.permissions) {
        perms.value = { ...perms.value, ...res.data.permissions };
      }
    }
  } catch (err) {
    ElMessage.error('初始化讨论区失败: ' + err.message);
  }
};

const fetchTopics = async (page = 1) => {
  loadingList.value = true;
  currentPage.value = page;
  try {
    const res = await request.get('/discuss/list_topics', {
      params: {
        category_id: selectedCategory.value,
        search: searchKw.value.trim(),
        page: currentPage.value,
        per_page: perPage.value
      }
    });
    if (res.data) {
      topics.value = res.data.topics || [];
      total.value = res.data.total || 0;
    }
  } catch (err) {
    ElMessage.error('加载话题列表失败');
  } finally {
    loadingList.value = false;
  }
};

const handleCategoryChange = () => fetchTopics(1);
const handleSearch = () => fetchTopics(1);
const handlePageChange = (p) => fetchTopics(p);

const viewTopicDetail = async (id) => {
  viewMode.value = 'detail';
  loadingDetail.value = true;
  try {
    const res = await request.get('/discuss/get_topic_detail', { params: { id } });
    if (res.data) {
      currentTopic.value = res.data.topic;
      replies.value = res.data.replies || [];
    } else {
      ElMessage.error(res.message || '获取帖子详情失败');
      viewMode.value = 'list';
    }
  } catch (err) {
    ElMessage.error('加载帖子详情异常');
    viewMode.value = 'list';
  } finally {
    loadingDetail.value = false;
  }
};

const backToList = () => {
  viewMode.value = 'list';
  fetchTopics(currentPage.value);
};

const submitReply = async () => {
  // ★ 富文本判空：内容可能只是 <p><br></p> 这类空壳，不能直接用字符串 trim
  if (isBlankHtml(replyForm.value.content)) {
    ElMessage.warning('请输入回复内容');
    return;
  }
  submittingReply.value = true;
  try {
    await request.post('/discuss/reply_topic', {
      topic_id: currentTopic.value.id,
      content: replyForm.value.content
    });
    ElMessage.success('回复发表成功');
    clearReply();
    viewTopicDetail(currentTopic.value.id);
  } catch (err) {
    ElMessage.error('回复失败: ' + err.message);
  } finally {
    submittingReply.value = false;
  }
};

/** 清空回帖编辑器 */
const clearReply = () => {
  replyForm.value.content = '';
  replyEditorRef.value?.clear();
};

/**
 * ★ 楼层引用：把某层回复以 blockquote 形式带入编辑器（Discuz/phpwind 的经典操作）
 *       只截取纯文本前 300 字，避免多人层层引用把正文撑爆。
 */
const quoteReply = (reply, index) => {
  const quote = buildQuoteHtml({
    author: reply.author_name,
    floor: index + 1,
    content: reply.content
  });
  const ed = replyEditorRef.value;
  const base = ed ? ed.getHtml() : (replyForm.value.content || '');
  const next = (isBlankHtml(base) ? '' : base) + quote;

  if (ed) ed.setHtml(next);
  replyForm.value.content = next;

  nextTick(() => {
    ed?.focus();
    document.querySelector('.editor-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
};

const openCreateModal = () => {
  isEditing.value = false;
  topicForm.value = {
    id: 0,
    category_id: categories.value[0]?.id || null,
    title: '',
    content: ''
  };
  modalVisible.value = true;
};

const openEditModal = () => {
  isEditing.value = true;
  topicForm.value = {
    id: currentTopic.value.id,
    category_id: currentTopic.value.category_id,
    title: currentTopic.value.title,
    content: currentTopic.value.content
  };
  modalVisible.value = true;
};

const handleSaveTopic = async () => {
  if (!topicForm.value.title.trim() || isBlankHtml(topicForm.value.content)) {
    ElMessage.warning('请填写完整的标题和正文');
    return;
  }
  submittingTopic.value = true;
  try {
    const url = isEditing.value ? '/discuss/edit_topic' : '/discuss/create_topic';
    const res = await request.post(url, topicForm.value);
    ElMessage.success(isEditing.value ? '修改成功' : '发布成功');
    modalVisible.value = false;
    if (isEditing.value) {
      viewTopicDetail(topicForm.value.id);
    } else {
      viewTopicDetail(res.data.topic_id);
    }
  } catch (err) {
    ElMessage.error('保存失败: ' + err.message);
  } finally {
    submittingTopic.value = false;
  }
};

const togglePin = async () => {
  await request.post('/discuss/manage_action', {
    sub_action: 'toggle_pin',
    topic_id: currentTopic.value.id
  });
  viewTopicDetail(currentTopic.value.id);
};

const toggleClose = async () => {
  await request.post('/discuss/manage_action', {
    sub_action: 'toggle_close',
    topic_id: currentTopic.value.id
  });
  viewTopicDetail(currentTopic.value.id);
};

const confirmDeleteTopic = () => {
  ElMessageBox.confirm('确定要删除该帖子及其全部回复吗？', '提示', {
    type: 'warning'
  }).then(async () => {
    await request.post('/discuss/manage_action', {
      sub_action: 'delete_topic',
      topic_id: currentTopic.value.id
    });
    ElMessage.success('帖子已删除');
    backToList();
  });
};

const deleteReply = (replyId) => {
  ElMessageBox.confirm('确定要删除该条回复吗？', '提示', { type: 'warning' }).then(async () => {
    await request.post('/discuss/manage_action', {
      sub_action: 'delete_reply',
      reply_id: replyId
    });
    ElMessage.success('回复已删除');
    viewTopicDetail(currentTopic.value.id);
  });
};

const openNotifModal = async () => {
  notifModalVisible.value = true;
  loadingNotifs.value = true;
  try {
    const res = await request.get('/discuss/list_notifications');
    notifList.value = res.data?.notifications || [];
  } finally {
    loadingNotifs.value = false;
  }
};

const markAllNotifRead = async () => {
  await request.post('/discuss/mark_all_read');
  unreadNotifs.value = 0;
  notifList.value.forEach(n => n.is_read = 1);
  ElMessage.success('已全部标记为已读');
};

const clickNotification = (item) => {
  notifModalVisible.value = false;
  viewTopicDetail(item.topic_id);
};

const formatTime = (timeStr) => {
  return String(timeStr || '').substring(0, 16);
};

onMounted(async () => {
  await initData();
  fetchTopics(1);
});
</script>

<style scoped>
.discuss-container {
  padding: 16px;
  background-color: #f5f7fa;
  min-height: calc(100vh - 100px);
}
.header-card, .category-filter-card, .topic-list-card, .detail-card, .reply-card, .editor-card {
  border-radius: 8px;
}
.header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.header-title {
  font-size: 18px;
  font-weight: 600;
  display: flex;
  align-items: center;
}
.header-actions {
  display: flex;
  gap: 12px;
  align-items: center;
}
.topic-item {
  display: flex;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 1px solid #ebeef5;
  cursor: pointer;
  transition: background-color 0.2s;
}
.topic-item:hover {
  background-color: #fafafa;
}
.custom-avatar {
  background: #409EFF;
  color: #fff;
  font-weight: bold;
}
.info-col {
  flex: 1;
  margin-left: 14px;
}
.title-row {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 6px;
}
.meta-row {
  font-size: 12px;
  color: #909399;
  display: flex;
  gap: 12px;
  align-items: center;
}
.stat-col {
  display: flex;
  gap: 20px;
  text-align: center;
}
.stat-box .count {
  font-size: 15px;
  font-weight: bold;
  color: #303133;
  display: block;
}
.stat-box .label {
  font-size: 11px;
  color: #909399;
}
.pagination-wrap {
  display: flex;
  justify-content: center;
  padding-top: 16px;
}
.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.detail-title {
  margin: 0;
  font-size: 20px;
  color: #303133;
}
.topic-content-body {
  font-size: 15px;
  line-height: 1.8;
  color: #2c3e50;
}

/* 富文本正文样式
   注：正文通过 v-safe-html 注入 innerHTML，scoped 属性不会落在这些子元素上，
   必须用 :deep() 才能命中。 */
.topic-content-body :deep(p) { margin: 0 0 10px; }
.topic-content-body :deep(p:last-child) { margin-bottom: 0; }
.topic-content-body :deep(h1),
.topic-content-body :deep(h2),
.topic-content-body :deep(h3) { margin: 14px 0 8px; font-weight: 600; }
.topic-content-body :deep(h1) { font-size: 20px; }
.topic-content-body :deep(h2) { font-size: 18px; }
.topic-content-body :deep(h3) { font-size: 16px; }
.topic-content-body :deep(blockquote) {
  margin: 10px 0;
  padding: 8px 12px;
  border-left: 3px solid #c8d6e5;
  background: #f6f8fa;
  color: #5a6673;
  border-radius: 0 4px 4px 0;
}
.topic-content-body :deep(pre) {
  margin: 10px 0;
  padding: 10px 12px;
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  font-family: Consolas, Monaco, "Courier New", monospace;
  font-size: 13px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-x: auto;
}
.topic-content-body :deep(code) {
  padding: 1px 5px;
  background: #f2f3f5;
  border-radius: 3px;
  font-family: Consolas, Monaco, monospace;
  font-size: 13px;
}
.topic-content-body :deep(ul),
.topic-content-body :deep(ol) { margin: 8px 0; padding-left: 24px; }
.topic-content-body :deep(li) { margin: 3px 0; }
.topic-content-body :deep(a) { color: #409eff; text-decoration: none; }
.topic-content-body :deep(a:hover) { text-decoration: underline; }
.topic-content-body :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 4px;
  vertical-align: bottom;
}
.topic-content-body :deep(hr) { border: none; border-top: 1px dashed #dcdfe6; margin: 12px 0; }
.topic-content-body :deep(table) {
  border-collapse: collapse;
  margin: 8px 0;
  max-width: 100%;
}
.topic-content-body :deep(td),
.topic-content-body :deep(th) {
  border: 1px solid #dcdfe6;
  padding: 4px 8px;
}
.reply-item {
  display: flex;
  padding: 14px 0;
  border-bottom: 1px solid #f2f6fc;
}
.reply-content-col {
  flex: 1;
}
.reply-meta {
  display: flex;
  align-items: center;
  font-size: 12px;
  margin-bottom: 6px;
}
.reply-meta .author-name {
  font-weight: 600;
  color: #303133;
}
.reply-meta .floor-date {
  color: #909399;
  margin-left: 8px;
}
.reply-meta .action-btns {
  margin-left: auto;
}
.reply-text {
  font-size: 14px;
  line-height: 1.6;
  color: #606266;
}
.reply-text :deep(p) { margin: 0 0 6px; }
.reply-text :deep(p:last-child) { margin-bottom: 0; }
.reply-text :deep(blockquote) {
  margin: 6px 0;
  padding: 5px 10px;
  border-left: 3px solid #c8d6e5;
  background: #f6f8fa;
  color: #7a8794;
  font-size: 13px;
  border-radius: 0 4px 4px 0;
}
.reply-text :deep(pre) {
  margin: 6px 0;
  padding: 8px 10px;
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  font-family: Consolas, Monaco, "Courier New", monospace;
  font-size: 12.5px;
  white-space: pre-wrap;
  overflow-x: auto;
}
.reply-text :deep(code) {
  padding: 1px 4px;
  background: #f2f3f5;
  border-radius: 3px;
  font-family: Consolas, Monaco, monospace;
}
.reply-text :deep(a) { color: #409eff; text-decoration: none; }
.reply-text :deep(a:hover) { text-decoration: underline; }
.reply-text :deep(img) { max-width: 100%; height: auto; border-radius: 4px; vertical-align: bottom; }
.reply-text :deep(ul), .reply-text :deep(ol) { margin: 6px 0; padding-left: 22px; }
.editor-bottom-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.notif-item {
  padding: 10px 12px;
  border-bottom: 1px solid #ebeef5;
  cursor: pointer;
  border-radius: 4px;
}
.notif-item:hover {
  background-color: #f5f7fa;
}
.notif-item.unread {
  background-color: #f0f7ff;
}
.empty-wrap {
  padding: 30px 0;
}
.mr-1 { margin-right: 4px; }
.mr-2 { margin-right: 8px; }
.mr-3 { margin-right: 12px; }
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
.mb-3 { margin-bottom: 12px; }
</style>