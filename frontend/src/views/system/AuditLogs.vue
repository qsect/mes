<template>
  <el-card shadow="never">
    <el-table :data="logs" v-loading="loading" border stripe>
      <el-table-column prop="id" label="ID" width="70" align="center" />
      <el-table-column prop="username" label="操作人" width="120">
        <template #default="{ row }">
          {{ row.full_name }} ({{ row.username }})
        </template>
      </el-table-column>
      <el-table-column prop="module" label="所属模块" width="120" />
      <el-table-column prop="action" label="操作行为" width="180" />
      <el-table-column prop="ip" label="终端 IP" width="130" />
      <el-table-column prop="params" label="请求载荷" min-width="200" show-overflow-tooltip />
      <el-table-column prop="status" label="状态" width="80" align="center">
        <template #default="{ row }">
          <el-tag :type="row.status ? 'success' : 'danger'">{{ row.status ? '成功' : '失败' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="记录时间" width="170" />
    </el-table>

    <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
      <el-pagination
        v-model:current-page="page"
        v-model:page-size="limit"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="loadLogs"
      />
    </div>
  </el-card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import request from '@/api/request';

const logs = ref([]);
const loading = ref(false);
const page = ref(1);
const limit = ref(15);
const total = ref(0);

const loadLogs = async () => {
  loading.value = true;
  try {
    const res = await request.get('/system/audit-logs', {
      params: { page: page.value, limit: limit.value }
    });
    logs.value = res.data.list;
    total.value = res.data.total;
  } finally {
    loading.value = false;
  }
};

onMounted(loadLogs);
</script>
