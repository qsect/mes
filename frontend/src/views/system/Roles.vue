<template>
  <el-row :gutter="20">
    <el-col :span="10">
      <el-card shadow="never">
        <template #header>
          <span style="font-weight: 600;">系统角色列表</span>
        </template>
        <el-table
          :data="roles"
          highlight-current-row
          @current-change="handleSelectRole"
          border
        >
          <el-table-column prop="id" label="ID" width="60" align="center" />
          <el-table-column prop="name" label="角色名称" width="130" />
          <el-table-column prop="code" label="标识" width="130" />
          <el-table-column prop="description" label="说明" />
        </el-table>
      </el-card>
    </el-col>

    <el-col :span="14">
      <el-card shadow="never">
        <template #header>
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-weight: 600;">
              权限分配: {{ currentRole ? currentRole.name : '（请先在左侧选择角色）' }}
            </span>
            <el-button type="primary" :disabled="!currentRole" @click="savePermissions">
              保存权限分配
            </el-button>
          </div>
        </template>

        <el-tree
          ref="treeRef"
          :data="moduleTree"
          show-checkbox
          node-key="id"
          :props="{ label: 'title', children: 'children' }"
          default-expand-all
        />
      </el-card>
    </el-col>
  </el-row>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import request from '@/api/request';

const roles = ref([]);
const currentRole = ref(null);
const moduleTree = ref([]);
const treeRef = ref(null);

const buildTree = (items, parentId = 0) => {
  return items
    .filter(item => item.parent_id == parentId)
    .map(item => ({
      ...item,
      children: buildTree(items, item.id)
    }));
};

const loadInit = async () => {
  const [roleRes, modRes] = await Promise.all([
    request.get('/system/roles'),
    request.get('/system/modules')
  ]);
  roles.value = roleRes.data;
  moduleTree.value = buildTree(modRes.data, 0);
};

const handleSelectRole = async (row) => {
  if (!row) return;
  currentRole.value = row;
  const res = await request.get('/system/role-modules', { params: { role_id: row.id } });
  const ids = res.data.map(i => parseInt(i));
  treeRef.value.setCheckedKeys(ids);
};

const savePermissions = async () => {
  const checkedKeys = treeRef.value.getCheckedKeys();
  const halfCheckedKeys = treeRef.value.getHalfCheckedKeys();
  const allSelected = [...checkedKeys, ...halfCheckedKeys];

  await request.post('/system/role-modules/save', {
    role_id: currentRole.value.id,
    module_ids: allSelected
  });
  ElMessage.success('权限分配已更新');
};

onMounted(loadInit);
</script>
