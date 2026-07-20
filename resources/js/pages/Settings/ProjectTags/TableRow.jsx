import TableRowActions from '@/components/TableRowActions';
import { ColorSwatch, Group, Table, Text, Tooltip } from '@mantine/core';
import { IconEyeOff } from '@tabler/icons-react';

export default function TableRow({ item }) {
  return (
    <Table.Tr key={item.id}>
      <Table.Td w={80}>
        <ColorSwatch color={item.color} />
      </Table.Td>
      <Table.Td>
        <Group gap={6}>
          <Text fz='sm'>{item.name}</Text>
          {item.hide_by_default && (
            <Tooltip
              label='Tagged projects are hidden unless this tag is selected in the filter'
              withArrow
            >
              <IconEyeOff
                size={15}
                stroke={1.5}
                color='var(--mantine-color-dimmed)'
              />
            </Tooltip>
          )}
        </Group>
      </Table.Td>
      {(can('edit project tag') || can('archive project tag') || can('restore project tag')) && (
        <Table.Td w={100}>
          <TableRowActions
            item={item}
            editRoute='settings.project-tags.edit'
            editPermission='edit project tag'
            archivePermission='archive project tag'
            restorePermission='restore project tag'
            archive={{
              route: 'settings.project-tags.destroy',
              title: 'Archive project tag',
              content: 'Are you sure you want to archive this project tag?',
              confirmLabel: 'Archive',
            }}
            restore={{
              route: 'settings.project-tags.restore',
              title: 'Restore project tag',
              content: 'Are you sure you want to restore this project tag?',
              confirmLabel: 'Restore',
            }}
          />
        </Table.Td>
      )}
    </Table.Tr>
  );
}
