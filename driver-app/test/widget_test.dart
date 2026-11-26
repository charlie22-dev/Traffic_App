import 'package:flutter_test/flutter_test.dart';
import 'package:commutelive_driver/main.dart';

void main() {
  testWidgets('driver starter shows tracking off and the collection disclosure', (tester) async {
    await tester.pumpWidget(const MainApp());
    expect(find.text('CommuteLive Driver'), findsOneWidget);
    expect(find.text('Tracking OFF'), findsOneWidget);
    expect(find.textContaining('No location is collected.'), findsOneWidget);
  });
}
