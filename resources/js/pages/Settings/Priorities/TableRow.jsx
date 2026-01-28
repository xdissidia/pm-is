import TableRowActions from '@/components/TableRowActions';
import { ColorSwatch, Table, Text } from '@mantine/core';

export default function TableRow({ item }) {
  return (
    <Table.Tr key={item.id}>
      <Table.Td w={80}>
        <ColorSwatch color={item.color} />
      </Table.Td>
      <Table.Td>
        <Text fz='sm'>{item.label}</Text>
      </Table.Td>
      <Table.Td w={100}>
        <Text fz='sm'>{item.order}</Text>
      </Table.Td>
      {(can('edit task priority') || can('delete task priority')) && (
        <Table.Td w={100}>
          <TableRowActions
            item={item}
            editRoute='settings.task-priorities.edit'
            editPermission='edit task priority'
            archivePermission='delete task priority'
            archive={{
              route: 'settings.task-priorities.destroy',
              title: 'Delete priority',
              content: 'Are you sure you want to delete this priority?',
              confirmLabel: 'Delete',
            }}
          />
        </Table.Td>
      )}
    </Table.Tr>
  );
}
